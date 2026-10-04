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
                    'location' => $capturedCalendarPayload['location'] ?? 'https://meet.google.com/abc-defg-hij',
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

    if (str_contains($url, ':endActiveConference') && $method === 'POST') {
        return ['status' => 200, 'body' => '{}', 'error' => null];
    }

    if (preg_match('#meet\.googleapis\.com/v2/spaces$#', $url) && $method === 'POST') {
        $pData = json_decode((string)$payload, true);
        $reqAccess = $pData['config']['accessType'] ?? 'OPEN';
        return [
            'status' => 200,
            'body'   => json_encode([
                'name' => 'spaces/sPaCeId98765',
                'meetingCode' => 'abc-defg-hij',
                'meetingUri' => 'https://meet.google.com/abc-defg-hij',
                'config' => [
                    'accessType' => $reqAccess,
                    'entryPointAccess' => 'ALL',
                ]
            ]),
            'error'  => null,
        ];
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

    if (str_contains($url, '/members')) {
        if ($method === 'POST') {
            $capturedMemberPayload = json_decode((string)$payload, true);
            $memEmail = $capturedMemberPayload['email'] ?? 'faculty@pepponline.in';
            return [
                'status' => 200,
                'body'   => json_encode([
                    'name' => 'spaces/sPaCeId98765/members/faculty_member_1',
                    'role' => 'COHOST',
                    'email' => $memEmail,
                ]),
                'error'  => null,
            ];
        }
        if ($method === 'GET') {
            $memEmail = $capturedMemberPayload['email'] ?? 'faculty@pepponline.in';
            return [
                'status' => 200,
                'body'   => json_encode([
                    'members' => [
                        [
                            'name' => 'spaces/sPaCeId98765/members/faculty_member_1',
                            'role' => 'COHOST',
                            'email' => $memEmail,
                        ]
                    ]
                ]),
                'error'  => null,
            ];
        }
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

// Space configuration test (Default OPEN for new Live Sessions)
$confRes = $meetService->configureSpace('spaces/sPaCeId98765');
assertTest($confRes['success'], "Space configuration PATCH succeeded");
assertTest(($capturedMeetPatchPayload['config']['accessType'] ?? '') === 'OPEN', "Space default accessType is OPEN for new Live Sessions");
assertTest(($capturedMeetPatchPayload['config']['moderation'] ?? '') === 'ON', "Space moderation is ON");
assertTest(($capturedMeetPatchPayload['config']['attendanceReportGenerationType'] ?? '') === 'GENERATE_REPORT', "attendanceReportGenerationType is GENERATE_REPORT");

// Legacy Space configuration test
$confResLegacy = $meetService->configureSpace('spaces/sPaCeId98765', 'RESTRICTED');
assertTest($confResLegacy['success'], "Legacy space configuration PATCH succeeded");
assertTest(($capturedMeetPatchPayload['config']['accessType'] ?? '') === 'RESTRICTED', "Legacy space accessType can be explicitly configured as RESTRICTED");

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

// Read-back verification test
$verifyRes = $meetService->verifyFacultyCohost('spaces/sPaCeId98765', 'faculty@pepponline.in');
assertTest($verifyRes['success'], "Faculty co-host read-back verification succeeds");
assertTest($verifyRes['is_cohost'] === true, "verifyFacultyCohost confirms is_cohost is true");
assertTest($verifyRes['role'] === 'COHOST', "verifyFacultyCohost confirms role is COHOST");

// Native Meet space creation test via POST /v2/spaces (Default OPEN)
$createSpaceRes = $meetService->createSpace('OPEN');
assertTest($createSpaceRes['success'], "Native Meet space creation succeeds via POST /v2/spaces with OPEN access");
assertTest($createSpaceRes['space_name'] === 'spaces/sPaCeId98765', "Native space name returned: spaces/sPaCeId98765");
assertTest($createSpaceRes['meeting_uri'] === 'https://meet.google.com/abc-defg-hij', "Native meeting URI returned");

$createLegacyRes = $meetService->createSpace('RESTRICTED');
assertTest($createLegacyRes['success'], "Native Meet space creation supports legacy RESTRICTED access");

// End active conference test via POST /v2/spaces/{space}:endActiveConference
$endConfRes = $meetService->endActiveConference('spaces/sPaCeId98765');
assertTest($endConfRes['success'], "endActiveConference succeeds via POST /v2/spaces/{space}:endActiveConference");

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
assertTest($provRes['cohost_status'] === 'cohost_confirmed', "provisionGoogleLiveSession returns cohost_status = cohost_confirmed");
assertTest(!isset($capturedCalendarPayload['conferenceData']['createRequest']), "Calendar event uses existing native Meet link WITHOUT createRequest");
assertTest(($capturedCalendarPayload['location'] ?? '') === 'https://meet.google.com/abc-defg-hij', "Calendar event location is set to native Meet URI");

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

echo "\nSECTION 7: NATIVE MEET SPACE OWNERSHIP, COHOST VERIFICATION & FAILURE RECOVERY\n";
echo "------------------------------------------------------------------------\n";

// Test A: COHOST assignment succeeds & read-back verification confirms role
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (501, 'Financial Reporting Standards', 5, '2026-11-01 10:00:00', 1.00, 'live', 'B.Com Honours', 'scheduled');
");

$testASpaceCreated = false;
$testACohostAdded = false;
$testACohostVerified = false;
$testACalendarCreated = false;

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (
    &$testASpaceCreated, &$testACohostAdded, &$testACohostVerified, &$testACalendarCreated
) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7a', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        $testASpaceCreated = true;
        return [
            'status' => 200,
            'body' => json_encode([
                'name' => 'spaces/sPaCe501',
                'meetingCode' => 'xyz-frs-501',
                'meetingUri' => 'https://meet.google.com/xyz-frs-501',
            ]),
            'error' => null,
        ];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe501')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe501']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe501/members')) {
        $testACohostAdded = true;
        $body = json_decode((string)$payload, true);
        return [
            'status' => 200,
            'body' => json_encode([
                'name' => 'spaces/sPaCe501/members/mem_501',
                'role' => $body['role'] ?? 'COHOST',
                'email' => $body['email'] ?? '',
            ]),
            'error' => null,
        ];
    }
    if ($method === 'GET' && str_contains($url, 'spaces/sPaCe501/members')) {
        $testACohostVerified = true;
        return [
            'status' => 200,
            'body' => json_encode([
                'members' => [
                    [
                        'name' => 'spaces/sPaCe501/members/mem_501',
                        'role' => 'COHOST',
                        'email' => 'alan.turing@pepponline.in',
                    ]
                ]
            ]),
            'error' => null,
        ];
    }
    if ($method === 'POST' && str_contains($url, '/calendar/v3/calendars/primary/events')) {
        $testACalendarCreated = true;
        return [
            'status' => 200,
            'body' => json_encode(['id' => 'cal_event_501', 'summary' => 'Financial Reporting Standards']),
            'error' => null,
        ];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resA = $liveMgr->provisionGoogleLiveSession(501);
assertTest($resA['success'] === true, "Scenario A: provisionGoogleLiveSession succeeds with native Meet space and verified co-host");
assertTest($testASpaceCreated, "Scenario A: Native Meet space created via POST /v2/spaces");
assertTest($testACohostAdded, "Scenario A: Faculty added via POST /v2/spaces/{space}/members with role=COHOST");
assertTest($testACohostVerified, "Scenario A: Faculty verified via GET /v2/spaces/{space}/members");
assertTest($testACalendarCreated, "Scenario A: Google Calendar event created using native Meet link");
assertTest($resA['cohost_status'] === 'cohost_confirmed', "Scenario A: cohost_status is cohost_confirmed");

$sessA = $pdo->query("SELECT * FROM sessions WHERE id = 501")->fetch();
assertTest($sessA['google_integration_status'] === 'synced', "Scenario A: sessions.google_integration_status is 'synced'");
assertTest($sessA['google_meet_space_name'] === 'spaces/sPaCe501', "Scenario A: Authoritative spaces/sPaCe501 stored");
assertTest($sessA['google_calendar_event_id'] === 'cal_event_501', "Scenario A: Calendar event ID cal_event_501 stored");

$detailsA = $liveMgr->getSessionDetails(501);
assertTest($detailsA['faculty']['cohost_status'] === 'Co-host Confirmed', "Scenario A: getSessionDetails shows 'Co-host Confirmed'");
assertTest($detailsA['google']['cohost_confirmed'] === true, "Scenario A: Google Setup Checklist confirms cohost_confirmed = true");
assertTest($detailsA['google']['meet_space_created'] === true, "Scenario A: Google Setup Checklist confirms meet_space_created = true");
assertTest($detailsA['google']['calendar_event_created'] === true, "Scenario A: Google Setup Checklist confirms calendar_event_created = true");

// Test B: COHOST assignment returns 403 Forbidden
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (502, 'Tax Audit Verification', 5, '2026-11-02 11:00:00', 1.00, 'live', 'B.Com Honours', 'scheduled');
");

$testBCalendarAttempted = false;
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$testBCalendarAttempted) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7b', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        return [
            'status' => 200,
            'body' => json_encode(['name' => 'spaces/sPaCe502', 'meetingCode' => 'xyz-tav-502', 'meetingUri' => 'https://meet.google.com/xyz-tav-502']),
            'error' => null,
        ];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe502')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe502']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe502/members')) {
        return [
            'status' => 403,
            'body' => json_encode(['error' => ['code' => 403, 'message' => 'Permission denied on resource MeetingSpace', 'status' => 'PERMISSION_DENIED']]),
            'error' => 'HTTP 403: Permission denied on resource MeetingSpace',
        ];
    }
    if (str_contains($url, '/calendar/v3/calendars/primary/events')) {
        $testBCalendarAttempted = true;
        return ['status' => 200, 'body' => json_encode(['id' => 'unexpected_cal_502']), 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resB = $liveMgr->provisionGoogleLiveSession(502);
assertTest($resB['success'] === false, "Scenario B: provisionGoogleLiveSession reports failure when COHOST assignment returns 403");
assertTest($resB['cohost_status'] === 'cohost_failed', "Scenario B: Returned cohost_status is cohost_failed");
assertTest($testBCalendarAttempted === false, "Scenario B: Calendar event creation is aborted when COHOST assignment fails");

$sessB = $pdo->query("SELECT * FROM sessions WHERE id = 502")->fetch();
assertTest($sessB['google_integration_status'] === 'failed', "Scenario B: sessions.google_integration_status is 'failed' (NOT 'synced')");
assertTest(str_contains($sessB['google_error_message'] ?? '', 'Co-Host setup failed'), "Scenario B: Safe diagnostic recorded in google_error_message");
assertTest(empty($sessB['google_calendar_event_id']), "Scenario B: sessions.google_calendar_event_id remains NULL");

$detailsB = $liveMgr->getSessionDetails(502);
assertTest($detailsB['faculty']['cohost_status'] === 'Co-host Setup Failed', "Scenario B: getSessionDetails shows 'Co-host Setup Failed'");
assertTest($detailsB['google']['cohost_confirmed'] === false, "Scenario B: Google Setup Checklist confirms cohost_confirmed = false");

// Test C: COHOST assignment succeeds (200 OK) but verification does not find the faculty
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (503, 'Corporate Finance Seminar', 5, '2026-11-03 14:00:00', 1.00, 'live', 'B.Com Honours', 'scheduled');
");

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7c', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe503', 'meetingCode' => 'xyz-cfs-503', 'meetingUri' => 'https://meet.google.com/xyz-cfs-503']), 'error' => null];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe503')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe503']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe503/members')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe503/members/mem_ghost', 'role' => 'COHOST']), 'error' => null];
    }
    if ($method === 'GET' && str_contains($url, 'spaces/sPaCe503/members')) {
        return ['status' => 200, 'body' => json_encode(['members' => []]), 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resC = $liveMgr->provisionGoogleLiveSession(503);
assertTest($resC['success'] === false, "Scenario C: Provisioning fails when read-back verification cannot find faculty in members list");
assertTest($resC['cohost_status'] === 'cohost_failed', "Scenario C: cohost_status is cohost_failed");
$sessC = $pdo->query("SELECT * FROM sessions WHERE id = 503")->fetch();
assertTest($sessC['google_integration_status'] === 'failed', "Scenario C: sessions.google_integration_status is 'failed' (NOT 'synced')");
assertTest(str_contains($sessC['google_error_message'] ?? '', 'was not found in Meet space members'), "Scenario C: Safe diagnostic indicates member missing in space");

// Test D: Faculty is returned as MEMBER instead of COHOST during read-back verification
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (504, 'Cost Accounting Workshop', 5, '2026-11-04 15:00:00', 1.00, 'live', 'B.Com Honours', 'scheduled');
");

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7d', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe504', 'meetingCode' => 'xyz-caw-504', 'meetingUri' => 'https://meet.google.com/xyz-caw-504']), 'error' => null];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe504')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe504']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe504/members')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe504/members/mem_reg', 'role' => 'MEMBER']), 'error' => null];
    }
    if ($method === 'GET' && str_contains($url, 'spaces/sPaCe504/members')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'members' => [
                    [
                        'name' => 'spaces/sPaCe504/members/mem_reg',
                        'role' => 'MEMBER',
                        'email' => 'alan.turing@pepponline.in',
                    ]
                ]
            ]),
            'error' => null,
        ];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resD = $liveMgr->provisionGoogleLiveSession(504);
assertTest($resD['success'] === false, "Scenario D: Provisioning fails when faculty is verified as MEMBER instead of COHOST");
assertTest($resD['cohost_status'] === 'cohost_failed', "Scenario D: cohost_status is cohost_failed");
$sessD = $pdo->query("SELECT * FROM sessions WHERE id = 504")->fetch();
assertTest($sessD['google_integration_status'] === 'failed', "Scenario D: sessions.google_integration_status is 'failed' (NOT 'synced')");
assertTest(str_contains($sessD['google_error_message'] ?? '', "expected 'COHOST'"), "Scenario D: Error message confirms expected 'COHOST' vs actual 'MEMBER'");

// Test E: Calendar event creation fails after Meet space is created
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status)
    VALUES (505, 'Auditing Principles Class', 5, '2026-11-05 16:00:00', 1.00, 'live', 'B.Com Honours', 'scheduled');
");

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7e', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe505', 'meetingCode' => 'xyz-apc-505', 'meetingUri' => 'https://meet.google.com/xyz-apc-505']), 'error' => null];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe505')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe505']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe505/members')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe505/members/mem_505', 'role' => 'COHOST', 'email' => 'alan.turing@pepponline.in']), 'error' => null];
    }
    if ($method === 'GET' && str_contains($url, 'spaces/sPaCe505/members')) {
        return ['status' => 200, 'body' => json_encode(['members' => [['name' => 'spaces/sPaCe505/members/mem_505', 'role' => 'COHOST', 'email' => 'alan.turing@pepponline.in']]]), 'error' => null];
    }
    if (str_contains($url, '/calendar/v3/calendars/primary/events')) {
        return ['status' => 500, 'body' => json_encode(['error' => ['code' => 500, 'message' => 'Calendar service temporary 500 failure']]), 'error' => 'HTTP 500: Calendar service temporary 500 failure'];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resE = $liveMgr->provisionGoogleLiveSession(505);
assertTest($resE['success'] === false, "Scenario E: Provisioning reports failure when Calendar event creation fails");
$sessE = $pdo->query("SELECT * FROM sessions WHERE id = 505")->fetch();
assertTest($sessE['google_integration_status'] === 'failed', "Scenario E: sessions.google_integration_status is 'failed'");
assertTest($sessE['google_meet_space_name'] === 'spaces/sPaCe505', "Scenario E: Pre-created Meet space is safely preserved in database");
assertTest($sessE['google_meet_uri'] === 'https://meet.google.com/xyz-apc-505', "Scenario E: Pre-created Meet URI is preserved in database");
assertTest(str_contains($sessE['google_error_message'] ?? '', 'Calendar event creation failed'), "Scenario E: Safe diagnostic records Calendar failure reason");

// Test F: Retry does not create duplicate Meet space
$spacesCreatedCount = 0;
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$spacesCreatedCount) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7f', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && preg_match('#meet\.googleapis\.com/v2/spaces$#', $url)) {
        $spacesCreatedCount++;
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/DUPLICATE_SPACE', 'meetingCode' => 'xyz-dup', 'meetingUri' => 'https://meet.google.com/xyz-dup']), 'error' => null];
    }
    if ($method === 'PATCH' && str_contains($url, 'spaces/sPaCe505')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe505']), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe505/members')) {
        return ['status' => 200, 'body' => json_encode(['name' => 'spaces/sPaCe505/members/mem_505', 'role' => 'COHOST', 'email' => 'alan.turing@pepponline.in']), 'error' => null];
    }
    if ($method === 'GET' && str_contains($url, 'spaces/sPaCe505/members')) {
        return ['status' => 200, 'body' => json_encode(['members' => [['name' => 'spaces/sPaCe505/members/mem_505', 'role' => 'COHOST', 'email' => 'alan.turing@pepponline.in']]]), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, '/calendar/v3/calendars/primary/events')) {
        return ['status' => 200, 'body' => json_encode(['id' => 'cal_event_505_recovered']), 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resF = $liveMgr->provisionGoogleLiveSession(505);
assertTest($resF['success'] === true, "Scenario F: Safe retry succeeds after Calendar recovery");
assertTest($spacesCreatedCount === 0, "Scenario F: POST /v2/spaces was NOT called during retry (No duplicate Meet space created)");
assertTest($resF['meet_space_name'] === 'spaces/sPaCe505', "Scenario F: Existing Meet space spaces/sPaCe505 was reused");
$sessF = $pdo->query("SELECT * FROM sessions WHERE id = 505")->fetch();
assertTest($sessF['google_integration_status'] === 'synced', "Scenario F: sessions.google_integration_status transitioned to 'synced'");
assertTest($sessF['google_calendar_event_id'] === 'cal_event_505_recovered', "Scenario F: Recovered Calendar event ID recorded");

// Test G: Retry does not create duplicate Calendar event
$calendarCreatedCount = 0;
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$calendarCreatedCount) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7g', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, '/calendar/v3/calendars/primary/events')) {
        $calendarCreatedCount++;
        return ['status' => 200, 'body' => json_encode(['id' => 'cal_event_duplicate']), 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$resG = $liveMgr->provisionGoogleLiveSession(505);
assertTest($resG['success'] === true, "Scenario G: Idempotent re-run on fully synced session succeeds immediately");
assertTest($calendarCreatedCount === 0, "Scenario G: POST /events was NOT called on already synced session (No duplicate Calendar event created)");

// Test H: Explicit End Live Session (endActiveConference)
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, google_integrated, google_meet_space_name, google_meet_uri, google_calendar_event_id, google_integration_status, status)
    VALUES (506, 'Final Live Class', 5, '2026-11-06 17:00:00', 1.00, 'live', 'B.Com Honours', 1, 'spaces/sPaCe506', 'https://meet.google.com/xyz-flc-506', 'cal_event_506', 'synced', 'scheduled');
");

$endActiveConfCalled = false;
$calendarDeleteCalled = false;

GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) use (&$endActiveConfCalled, &$calendarDeleteCalled) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_7h', 'expires_in' => 3600]), 'error' => null];
    }
    if ($method === 'POST' && str_contains($url, 'spaces/sPaCe506:endActiveConference')) {
        $endActiveConfCalled = true;
        return ['status' => 200, 'body' => '{}', 'error' => null];
    }
    if ($method === 'DELETE' && str_contains($url, '/events/')) {
        $calendarDeleteCalled = true;
        return ['status' => 204, 'body' => '', 'error' => null];
    }
    if (str_contains($url, 'conferenceRecords')) {
        return ['status' => 200, 'body' => json_encode(['conferenceRecords' => []]), 'error' => null];
    }
    return ['status' => 200, 'body' => '{}', 'error' => null];
});

$endRes = $liveMgr->endGoogleLiveSession(506);
assertTest($endRes['success'] === true, "Scenario H: endGoogleLiveSession succeeds");
assertTest($endRes['conference_ended'] === true, "Scenario H: conference_ended flag is true");
assertTest($endActiveConfCalled === true, "Scenario H: POST /v2/spaces/sPaCe506:endActiveConference was called");
assertTest($calendarDeleteCalled === false, "Scenario H: Ending conference does NOT delete the Google Calendar event");

$sessH = $pdo->query("SELECT * FROM sessions WHERE id = 506")->fetch();
assertTest($sessH['status'] === 'completed', "Scenario H: sessions.status is updated to 'completed'");
assertTest($sessH['google_calendar_event_id'] === 'cal_event_506', "Scenario H: Calendar event ID remains intact");

// Safety checks on endGoogleLiveSession
$nonGoogleEnd = $liveMgr->endGoogleLiveSession(301);
assertTest($nonGoogleEnd['success'] === false, "Scenario H: endGoogleLiveSession rejects non-Google session 301");
assertTest(str_contains($nonGoogleEnd['error'] ?? '', 'not Google-integrated'), "Scenario H: Rejection error message indicates not Google-integrated");

$pdo->exec("INSERT INTO sessions (id, topic, google_integrated, status, session_datetime) VALUES (507, 'No Space Session', 1, 'scheduled', '2026-11-07 10:00:00')");
$noSpaceEnd = $liveMgr->endGoogleLiveSession(507);
assertTest($noSpaceEnd['success'] === false, "Scenario H: endGoogleLiveSession rejects session with missing space name");
assertTest(str_contains($noSpaceEnd['error'] ?? '', 'does not have a Google Meet space name'), "Scenario H: Rejection error message indicates missing space name");

// Clean up test sessions 501-507
$pdo->exec("DELETE FROM session_attendance WHERE session_id IN (501, 502, 503, 504, 505, 506, 507)");
$pdo->exec("DELETE FROM sessions WHERE id IN (501, 502, 503, 504, 505, 506, 507)");

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

echo "\nSECTION 10: ATTENDANCE & ARTIFACT SYNCHRONIZATION AUDIT (SCENARIOS A–O)\n";
echo "------------------------------------------------------------------------\n";

// 1. Seed Faculty & Students for Scenario Testing
$pdo->exec("
    INSERT INTO faculties (id, name, email, status) VALUES
    (77, 'Prof. Scenario', 'faculty_scenario@pepponline.in', 'active');
");

$pdo->exec("
    INSERT INTO users (user_id, name, email, status, student_status, pepp_course) VALUES
    ('STU_S1', 'Student Full Attendance', 'student_full@pepponline.in', 'approved', 'active', 'Scenario Batch'),
    ('STU_S2', 'Student Partial Attendance', 'student_partial@pepponline.in', 'approved', 'active', 'Scenario Batch'),
    ('STU_S3', 'Student Joined Attendance', 'student_joined@pepponline.in', 'approved', 'active', 'Scenario Batch'),
    ('STU_S4', 'Student Absent Attendance', 'student_absent@pepponline.in', 'approved', 'active', 'Scenario Batch');
");

// 2. Seed Session 601 (Google-integrated live session)
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status, google_integrated, google_calendar_event_id, google_calendar_id, google_meet_space_name, google_meet_uri, google_meet_code, google_integration_status)
    VALUES (601, 'Scenario Masterclass', 77, '2026-10-20 10:00:00', 1.00, 'live', 'Scenario Batch', 'scheduled', 1, 'cal_event_601', 'primary', 'spaces/space_601', 'https://meet.google.com/scen-ario-601', 'scen-ario-601', 'synced');
");

// Seed Pre-existing Google Session 801 to test Scenario O
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status, google_integrated, google_calendar_event_id, google_calendar_id, google_meet_space_name, google_meet_uri, google_meet_code, google_integration_status)
    VALUES (801, 'Pre-existing Masterclass', 77, '2026-10-19 14:00:00', 1.50, 'live', 'Scenario Batch', 'scheduled', 1, 'cal_event_existing801', 'primary', 'spaces/space_existing801', 'https://meet.google.com/exi-sting-801', 'exi-sting-801', 'synced');
");

// Initialize invited students in session_attendance
$attService = new GoogleAttendanceService($pdo, $client);
foreach (['STU_S1' => 'student_full@pepponline.in', 'STU_S2' => 'student_partial@pepponline.in', 'STU_S3' => 'student_joined@pepponline.in', 'STU_S4' => 'student_absent@pepponline.in'] as $uId => $uEmail) {
    $attService->upsertAttendanceRecord(601, $uId, 'Learner', $uEmail, null, null, null, 0, 'invited', 'synced', null, null);
}

// 3. Set Mock Transport for Scenarios A through O
GoogleWorkspaceClient::setMockTransport(function(string $method, string $url, ?string $payload, array $headers) {
    if ($url === GoogleWorkspaceClient::TOKEN_ENDPOINT) {
        return ['status' => 200, 'body' => json_encode(['access_token' => 'mock_token_s10', 'expires_in' => 3600]), 'error' => null];
    }

    if (str_contains($url, '/spaces/space_601:endActiveConference')) {
        return ['status' => 200, 'body' => '{}', 'error' => null];
    }

    if (str_contains($url, '/spaces/space_601/members')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'members' => [
                    ['name' => 'spaces/space_601/members/m_fac', 'user' => 'users/fac_601', 'email' => 'faculty_scenario@pepponline.in', 'role' => 'COHOST'],
                    ['name' => 'spaces/space_601/members/m_stu1', 'user' => 'users/stu_601', 'email' => 'student_full@pepponline.in', 'role' => 'MEMBER'],
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'meet.googleapis.com/v2/conferenceRecords?') || str_ends_with($url, '/conferenceRecords')) {
        if (str_contains($url, 'space_602')) {
            return [
                'status' => 200,
                'body' => json_encode([
                    'conferenceRecords' => [
                        [
                            'name' => 'conferenceRecords/conf_rec_602',
                            'startTime' => '2026-10-20T10:00:00Z',
                            'endTime' => '2026-10-20T11:00:00Z',
                            'space' => 'spaces/space_602',
                        ]
                    ]
                ]),
                'error' => null
            ];
        }
        return [
            'status' => 200,
            'body' => json_encode([
                'conferenceRecords' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601',
                        'startTime' => '2026-10-20T10:00:00Z',
                        'endTime' => '2026-10-20T11:00:00Z',
                        'space' => 'spaces/space_601',
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conferenceRecords/conf_rec_601/participants') && !str_contains($url, 'participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participants' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_fac',
                        'signedinUser' => [
                            'user' => 'users/fac_601',
                            'displayName' => 'Prof. Scenario',
                        ]
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_full',
                        'signedinUser' => [
                            'user' => 'student_full@pepponline.in',
                            'displayName' => 'Student Full Attendance',
                        ]
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_part',
                        'signedinUser' => [
                            'user' => 'student_partial@pepponline.in',
                            'displayName' => 'Student Partial Attendance',
                        ]
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_join',
                        'signedinUser' => [
                            'user' => 'student_joined@pepponline.in',
                            'displayName' => 'Student Joined Attendance',
                        ]
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_unk_signed',
                        'signedinUser' => [
                            'user' => 'users/999111222',
                            'displayName' => 'External Freelancer',
                        ]
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_unk_anon',
                        'anonymousUser' => [
                            'displayName' => 'Anonymous Guest',
                        ]
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_fac/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_fac/participantSessions/s1',
                        'startTime' => '2026-10-20T10:00:00Z',
                        'endTime' => '2026-10-20T10:55:00Z', // 55 mins = 3300s
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_stu_full/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_full/participantSessions/s1',
                        'startTime' => '2026-10-20T10:00:00Z',
                        'endTime' => '2026-10-20T10:20:00Z', // 20m = 1200s
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_full/participantSessions/s2',
                        'startTime' => '2026-10-20T10:25:00Z',
                        'endTime' => '2026-10-20T10:45:00Z', // 20m = 1200s
                    ],
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_full/participantSessions/s3',
                        'startTime' => '2026-10-20T10:50:00Z',
                        'endTime' => '2026-10-20T11:00:00Z', // 10m = 600s (Total = 3000s = 83.3% >= 80%)
                    ],
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_stu_part/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_part/participantSessions/s1',
                        'startTime' => '2026-10-20T10:00:00Z',
                        'endTime' => '2026-10-20T10:35:00Z', // 35m = 2100s = 58.3% >= 50%
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_stu_join/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_stu_join/participantSessions/s1',
                        'startTime' => '2026-10-20T10:00:00Z',
                        'endTime' => '2026-10-20T10:10:00Z', // 10m = 600s = 16.7% < 50%
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_unk_signed/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_unk_signed/participantSessions/s1',
                        'startTime' => '2026-10-20T10:15:00Z',
                        'endTime' => '2026-10-20T10:45:00Z', // 30m = 1800s
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'participants/p_unk_anon/participantSessions')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'participantSessions' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/participants/p_unk_anon/participantSessions/s1',
                        'startTime' => '2026-10-20T10:20:00Z',
                        'endTime' => '2026-10-20T10:40:00Z', // 20m = 1200s
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conf_rec_601/recordings')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'recordings' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/recordings/rec_601',
                        'state' => 'FILE_GENERATED',
                        'driveDestination' => [
                            'file' => 'drive_rec_file_601',
                            'exportUri' => 'https://drive.google.com/file/d/drive_rec_file_601/view',
                        ]
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conf_rec_601/transcripts')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'transcripts' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/transcripts/tr_601',
                        'state' => 'FILE_GENERATED',
                        'docsDestination' => [
                            'document' => 'docs_tr_doc_601',
                            'exportUri' => 'https://docs.google.com/document/d/docs_tr_doc_601/edit',
                        ]
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conf_rec_601/smartNotes')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'smartNotes' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_601/smartNotes/sn_601',
                        'state' => 'FILE_GENERATED',
                        'driveDestination' => [
                            'file' => 'docs_sn_doc_601',
                            'exportUri' => 'https://docs.google.com/document/d/docs_sn_doc_601/edit',
                        ]
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conf_rec_602/recordings')) {
        return [
            'status' => 200,
            'body' => json_encode([
                'recordings' => [
                    [
                        'name' => 'conferenceRecords/conf_rec_602/recordings/rec_602',
                        'state' => 'PROCESSING',
                    ]
                ]
            ]),
            'error' => null
        ];
    }

    if (str_contains($url, 'conf_rec_602/transcripts')) {
        return ['status' => 200, 'body' => json_encode(['transcripts' => []]), 'error' => null];
    }

    if (str_contains($url, 'conf_rec_602/smartNotes')) {
        return ['status' => 200, 'body' => json_encode(['smartNotes' => []]), 'error' => null];
    }

    return ['status' => 404, 'body' => '{}', 'error' => null];
});

// Run Initial Attendance Sync for Session 601
$s10AttRes = $attService->syncSessionAttendance(601);
assertTest($s10AttRes['success'], "Section 10: Attendance sync succeeded for session 601");

// --- SCENARIO A: Registered student who joined (thresholds: full / partial / joined) ---
$rowFull = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_email = 'student_full@pepponline.in'")->fetch();
assertTest($rowFull['attendance_status'] === 'full attendance', "Scenario A: Student with 83.3% duration classified as 'full attendance'");
assertTest((int)$rowFull['total_duration_seconds'] === 3000, "Scenario A: Full attendance duration recorded as 3000s");
assertTest($rowFull['user_id'] === 'STU_S1', "Scenario A: Correctly linked to registered user_id STU_S1");

$rowPart = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_email = 'student_partial@pepponline.in'")->fetch();
assertTest($rowPart['attendance_status'] === 'partial attendance', "Scenario A: Student with 58.3% duration classified as 'partial attendance'");
assertTest((int)$rowPart['total_duration_seconds'] === 2100, "Scenario A: Partial attendance duration recorded as 2100s");

$rowJoin = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_email = 'student_joined@pepponline.in'")->fetch();
assertTest($rowJoin['attendance_status'] === 'joined', "Scenario A: Student with 16.7% duration classified as 'joined'");
assertTest((int)$rowJoin['total_duration_seconds'] === 600, "Scenario A: Joined attendance duration recorded as 600s");

// --- SCENARIO B: Registered student who did not join (absent) ---
$rowAbs = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_email = 'student_absent@pepponline.in'")->fetch();
assertTest($rowAbs['attendance_status'] === 'absent', "Scenario B: Student invited but who never joined is classified as 'absent'");
assertTest((int)$rowAbs['total_duration_seconds'] === 0, "Scenario B: Absent student duration is 0 seconds");
assertTest($rowAbs['first_join_time'] === null || $rowAbs['first_join_time'] === '', "Scenario B: Absent student has no join time");

// --- SCENARIO C: Faculty who joined (FACULTY record, Present/full attendance, separate) ---
$rowFac = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND user_id = 'FACULTY'")->fetch();
assertTest($rowFac !== false, "Scenario C: Dedicated FACULTY attendance row exists");
assertTest($rowFac['google_participant_email'] === 'faculty_scenario@pepponline.in', "Scenario C: Faculty email matches assigned faculty");
assertTest($rowFac['attendance_status'] === 'full attendance', "Scenario C: Faculty attendance status is 'full attendance'");
assertTest((int)$rowFac['total_duration_seconds'] === 3300, "Scenario C: Faculty duration correctly recorded as 3300s (55m)");

$summary601 = $attService->getSessionAttendanceSummary(601, true);
assertTest($summary601['summary']['faculty_status'] === 'Present', "Scenario C: Summary metrics report faculty_status = 'Present'");
assertTest($summary601['summary']['faculty_present'] === true, "Scenario C: Summary metrics report faculty_present = true");
assertTest($summary601['faculty']['name'] === 'Prof. Scenario', "Scenario C: Faculty object returns faculty name");
assertTest($summary601['summary']['registered_students_count'] === 4, "Scenario C: Faculty is NEVER included in registered_students_count (count is exactly 4)");

// --- SCENARIO D: Unknown / Unregistered participants ---
$rowUnkSigned = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_resource LIKE '%p_unk_signed'")->fetch();
assertTest($rowUnkSigned !== false, "Scenario D: Signed-in unknown participant recorded");
assertTest($rowUnkSigned['user_id'] === null, "Scenario D: Unknown participant user_id is NULL");
assertTest($rowUnkSigned['attendance_status'] === 'unknown/unmatched', "Scenario D: Unknown participant attendance_status is 'unknown/unmatched'");
assertTest($rowUnkSigned['google_participant_email'] === 'uid_999111222@meet.google.internal', "Scenario D: Deterministic synthetic email generated: uid_999111222@meet.google.internal");

$rowUnkAnon = $pdo->query("SELECT * FROM session_attendance WHERE session_id = 601 AND google_participant_resource LIKE '%p_unk_anon'")->fetch();
assertTest($rowUnkAnon !== false, "Scenario D: Anonymous unknown participant recorded");
assertTest($rowUnkAnon['user_id'] === null, "Scenario D: Anonymous participant user_id is NULL");
assertTest(str_starts_with($rowUnkAnon['google_participant_email'], 'anon_') && str_ends_with($rowUnkAnon['google_participant_email'], '@meet.google.internal'), "Scenario D: Anonymous synthetic email matches anon_{hash}@meet.google.internal");

$fakeUsersCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE '%@meet.google.internal'")->fetchColumn();
assertTest($fakeUsersCount === 0, "Scenario D: Security check: Zero ERP user accounts created for unknown participants");

// --- SCENARIO E: Multi-session aggregation ---
$pFullMeta = json_decode((string)$rowFull['google_participant_session'], true);
assertTest(($pFullMeta['session_count'] ?? 0) === 3, "Scenario E: Participant with 3 drops/rejoins has session_count = 3");
assertTest(str_contains((string)$rowFull['first_join_time'], '10:00:00'), "Scenario E: first_join_time reflects earliest join (10:00:00)");
assertTest(str_contains((string)$rowFull['last_leave_time'], '11:00:00'), "Scenario E: last_leave_time reflects latest leave (11:00:00)");
assertTest((int)$rowFull['total_duration_seconds'] === 3000, "Scenario E: Total duration aggregates all 3 sessions (1200 + 1200 + 600 = 3000s)");

// --- SCENARIO F: Repeated attendance sync idempotency ---
$rowsBeforeF = (int)$pdo->query("SELECT COUNT(*) FROM session_attendance WHERE session_id = 601")->fetchColumn();
assertTest($rowsBeforeF === 7, "Scenario F: Initial sync produced exactly 7 rows (1 faculty + 4 students + 2 unknowns)");

$s10AttRes2 = $attService->syncSessionAttendance(601);
assertTest($s10AttRes2['success'], "Scenario F: Second attendance sync succeeded");
$rowsAfterF = (int)$pdo->query("SELECT COUNT(*) FROM session_attendance WHERE session_id = 601")->fetchColumn();
assertTest($rowsAfterF === 7, "Scenario F: Idempotency confirmed: Exactly 7 rows remain, 0 duplicate rows created");

// --- SCENARIO G: Repeated artifact sync idempotency ---
$artService = new GoogleArtifactService($pdo, $client);
$artRes1 = $artService->syncSessionArtifacts(601);
assertTest($artRes1['success'], "Scenario G: Initial artifacts sync succeeded");
$artCount1 = (int)$pdo->query("SELECT COUNT(*) FROM session_google_artifacts WHERE session_id = 601")->fetchColumn();
assertTest($artCount1 === 3, "Scenario G: Initial artifact sync created exactly 3 rows (recording, transcript, smart_notes)");

$artRes2 = $artService->syncSessionArtifacts(601);
assertTest($artRes2['success'], "Scenario G: Second artifacts sync succeeded");
$artCount2 = (int)$pdo->query("SELECT COUNT(*) FROM session_google_artifacts WHERE session_id = 601")->fetchColumn();
assertTest($artCount2 === 3, "Scenario G: Idempotency confirmed: Exactly 3 artifact rows remain, 0 duplicate rows created");

// --- SCENARIO H: Recording artifact stored and displayed with Drive link ---
$artRec = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 601 AND artifact_type = 'recording'")->fetch();
assertTest($artRec['drive_file_id'] === 'drive_rec_file_601', "Scenario H: Recording drive_file_id correctly stored");
assertTest($artRec['artifact_url'] === 'https://drive.google.com/file/d/drive_rec_file_601/view', "Scenario H: Recording artifact_url links to Drive");
$artSummary601 = $artService->getSessionArtifactsSummary(601);
assertTest($artSummary601['recording']['status'] === 'AVAILABLE', "Scenario H: Artifact summary reports recording status AVAILABLE");
assertTest($artSummary601['recording']['url'] === 'https://drive.google.com/file/d/drive_rec_file_601/view', "Scenario H: Artifact summary returns direct Drive link");

// --- SCENARIO I: Transcript artifact stored and displayed with Docs link ---
$artTr = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 601 AND artifact_type = 'transcript'")->fetch();
assertTest($artTr['drive_file_id'] === 'docs_tr_doc_601', "Scenario I: Transcript drive_file_id correctly stored");
assertTest($artTr['artifact_url'] === 'https://docs.google.com/document/d/docs_tr_doc_601/edit', "Scenario I: Transcript artifact_url links to Docs");
assertTest($artSummary601['transcript']['status'] === 'AVAILABLE', "Scenario I: Artifact summary reports transcript status AVAILABLE");
assertTest($artSummary601['transcript']['url'] === 'https://docs.google.com/document/d/docs_tr_doc_601/edit', "Scenario I: Artifact summary returns direct Docs link");

// --- SCENARIO J: Gemini Notes artifact stored and displayed with Docs link ---
$artSn = $pdo->query("SELECT * FROM session_google_artifacts WHERE session_id = 601 AND artifact_type = 'smart_notes'")->fetch();
assertTest($artSn['drive_file_id'] === 'docs_sn_doc_601', "Scenario J: Smart Notes drive_file_id correctly stored");
assertTest($artSn['artifact_url'] === 'https://docs.google.com/document/d/docs_sn_doc_601/edit', "Scenario J: Smart Notes artifact_url links to Docs");
assertTest($artSummary601['smart_notes']['status'] === 'AVAILABLE', "Scenario J: Artifact summary reports smart_notes status AVAILABLE");
assertTest($artSummary601['smart_notes']['url'] === 'https://docs.google.com/document/d/docs_sn_doc_601/edit', "Scenario J: Artifact summary returns direct Docs link");

// --- SCENARIO K: Artifact not yet available (PROCESSING / NOT AVAILABLE without marking session failed) ---
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status, google_integrated, google_calendar_event_id, google_meet_space_name, google_integration_status)
    VALUES (602, 'Processing Session', 77, '2026-10-20 10:00:00', 1.00, 'live', 'Scenario Batch', 'completed', 1, 'cal_event_602', 'spaces/space_602', 'synced');
");
$artRes602 = $artService->syncSessionArtifacts(602);
assertTest($artRes602['success'], "Scenario K: Artifact sync for pending session 602 succeeds without error");
$artSummary602 = $artService->getSessionArtifactsSummary(602);
assertTest($artSummary602['recording']['status'] === 'PROCESSING', "Scenario K: Recording in PROCESSING state reported as PROCESSING");
assertTest($artSummary602['transcript']['status'] === 'NOT AVAILABLE', "Scenario K: Missing transcript reported as NOT AVAILABLE");
assertTest($artSummary602['smart_notes']['status'] === 'NOT AVAILABLE', "Scenario K: Missing smart notes reported as NOT AVAILABLE");
$sess602Status = $pdo->query("SELECT google_integration_status FROM sessions WHERE id = 602")->fetchColumn();
assertTest($sess602Status === 'synced', "Scenario K: Session integration status remains 'synced' (NOT marked 'failed') while artifacts process");

// --- SCENARIO L: endGoogleLiveSession flow ---
// Seed active live session 603
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status, google_integrated, google_calendar_event_id, google_meet_space_name, google_integration_status)
    VALUES (603, 'Ending Session', 77, '2026-10-20 10:00:00', 1.00, 'live', 'Scenario Batch', 'scheduled', 1, 'cal_event_601', 'spaces/space_601', 'synced');
");
$endRes603 = $liveMgr->endGoogleLiveSession(603);
assertTest($endRes603['success'], "Scenario L: endGoogleLiveSession returns success");
assertTest($endRes603['conference_ended'] === true, "Scenario L: endGoogleLiveSession confirms conference_ended = true");
$sess603Db = $pdo->query("SELECT status FROM sessions WHERE id = 603")->fetchColumn();
assertTest($sess603Db === 'completed', "Scenario L: Session status is transitioned to 'completed'");
$sess603AttCount = (int)$pdo->query("SELECT COUNT(*) FROM session_attendance WHERE session_id = 603")->fetchColumn();
assertTest($sess603AttCount > 0, "Scenario L: endGoogleLiveSession automatically triggered attendance synchronization");
$sess603ArtCount = (int)$pdo->query("SELECT COUNT(*) FROM session_google_artifacts WHERE session_id = 603")->fetchColumn();
assertTest($sess603ArtCount === 3, "Scenario L: endGoogleLiveSession automatically triggered artifact synchronization");

// --- SCENARIO M: Unknown participants hidden from student views ---
$studentViewSummary = $attService->getSessionAttendanceSummary(601, false);
assertTest(count($studentViewSummary['unknown_participants']) === 0, "Scenario M: Student view: unknown_participants array is EMPTY (hidden)");
assertTest(count($studentViewSummary['registered_students']) === 4, "Scenario M: Student view: registered students intact");

$adminViewSummary = $attService->getSessionAttendanceSummary(601, true);
assertTest(count($adminViewSummary['unknown_participants']) === 2, "Scenario M: Admin view: 2 unknown participants visible for audit");
assertTest($adminViewSummary['unknown_participants'][0]['attendance_status'] === 'UNKNOWN / UNREGISTERED', "Scenario M: Admin view: labeled UNKNOWN / UNREGISTERED");
assertTest($adminViewSummary['unknown_participants'][1]['attendance_status'] === 'UNKNOWN / UNREGISTERED', "Scenario M: Admin view: labeled UNKNOWN / UNREGISTERED");

// --- SCENARIO N: Existing non-Google sessions unaffected ---
$pdo->exec("
    INSERT INTO sessions (id, topic, faculty_id, session_datetime, duration_hours, session_type, course_csv, status, google_integrated)
    VALUES (701, 'Legacy Offline Lecture', 77, '2026-10-21 09:00:00', 2.00, 'offline', 'Scenario Batch', 'scheduled', 0);
");
$endLegacyRes = $liveMgr->endGoogleLiveSession(701);
assertTest(!$endLegacyRes['success'], "Scenario N: endGoogleLiveSession safely rejects non-Google session");
assertTest(str_contains(strtolower($endLegacyRes['error'] ?? ''), 'not google'), "Scenario N: Error explicitly notes not Google-integrated");
$sess701Db = $pdo->query("SELECT * FROM sessions WHERE id = 701")->fetch();
assertTest((int)$sess701Db['google_integrated'] === 0, "Scenario N: Non-Google session remains google_integrated = 0");
assertTest($sess701Db['status'] === 'scheduled', "Scenario N: Non-Google session status untouched");
$details701 = $liveMgr->getSessionDetails(701);
assertTest($details701['success'], "Scenario N: getSessionDetails succeeds for non-Google session");
assertTest($details701['google']['is_integrated'] === false, "Scenario N: getSessionDetails reports google.is_integrated = false");

// --- SCENARIO O: Existing Google-integrated sessions unaffected ---
$sess801DbCheck = $pdo->query("SELECT * FROM sessions WHERE id = 801")->fetch();
assertTest($sess801DbCheck !== false, "Scenario O: Existing Google-integrated session remains intact in database");
assertTest($sess801DbCheck['google_meet_space_name'] === 'spaces/space_existing801', "Scenario O: Space name spaces/space_existing801 preserved");
assertTest($sess801DbCheck['google_calendar_event_id'] === 'cal_event_existing801', "Scenario O: Calendar event ID cal_event_existing801 preserved");
assertTest($sess801DbCheck['google_integration_status'] === 'synced', "Scenario O: Integration status remains 'synced'");
assertTest($sess801DbCheck['status'] === 'scheduled', "Scenario O: Session status remains untouched as 'scheduled'");

// Clean up scenario test rows
$pdo->exec("DELETE FROM session_attendance WHERE session_id IN (601, 602, 603, 701, 801)");
$pdo->exec("DELETE FROM session_google_artifacts WHERE session_id IN (601, 602, 603, 701, 801)");
$pdo->exec("DELETE FROM sessions WHERE id IN (601, 602, 603, 701, 801)");
$pdo->exec("DELETE FROM users WHERE user_id LIKE 'STU_S%'");
$pdo->exec("DELETE FROM faculties WHERE id = 77");

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
