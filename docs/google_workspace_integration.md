# PEPP Learning ERP — Google Workspace Integration Architecture

**Authoritative Domain:** `pepponline.in`  
**Sole Organizer Account:** `admin@pepponline.in` (Google Workspace Business Plus)  
**Strictly Prohibited Account:** `meet@pepponline.in`  
**Google Cloud Project:** `pepp-live-sessions`  
**Service Account:** `pepp-erp-google-workspace@pepp-live-sessions.iam.gserviceaccount.com`  

---

## 1. Executive Summary & Philosophy

The PEPP Learning ERP retains full academic sovereignty as the single source of truth for all courses, faculties, active learners, and schedules. Google Calendar and Google Meet serve purely as the conferencing and scheduling layer.

To eliminate third-party SDK dependencies, external package drift, and Composer overhead on Hostinger cPanel shared hosting, the integration is built on pure vanilla PHP with OpenSSL (`openssl_sign` RS256) and cURL.

---

## 2. Authorized Google OAuth Scopes & Domain-Wide Delegation (DWD)

The Service Account uses **Domain-Wide Delegation (DWD)** to impersonate `admin@pepponline.in`.

### Authorized Scopes:
1. `https://www.googleapis.com/auth/calendar`  
   Creates, updates, and manages Live Session events on the organizer's primary calendar; requests unique Meet conferences; injects attendees; enforces guest privacy.
2. `https://www.googleapis.com/auth/meetings.space.created`  
   Manages meeting space members and assigns faculty `COHOST` roles via `spaces.members.create`.
3. `https://www.googleapis.com/auth/meetings.space.settings`  
   Configures meeting space settings (`accessType = 'RESTRICTED'`, `moderation = 'ON'`, `artifactConfig` for auto-recording, auto-transcription, smart notes).
4. `https://www.googleapis.com/auth/meetings.space.readonly`  
   Because PEPP Live Sessions creates Meet conferences through Google Calendar, `meetings.space.readonly` is required for resolving authoritative space metadata via `spaces.get` (`GET /v2/spaces/{meetingCode}`) on Calendar-created Meet spaces, as well as reading conference records, attendance participant sessions, and artifact references.

*Note: Drive-wide scopes (`https://www.googleapis.com/auth/drive`, `drive.readonly`, or `drive.meet.readonly`) are deliberately excluded. Artifact references are resolved via Google Meet REST API v2 endpoints linking directly to the organizer's Google Drive destination file IDs and export URLs without downloading files to Hostinger or granting broad Drive file access.*

---

## 3. Credential Storage & Security Invariants

1. **Storage Location Pattern**:  
   The service account JSON key file is stored strictly **outside** the public web root (`public_html`) on Hostinger:  
   `/home/u361910773/google-secrets/pepp-erp-google-workspace.json`  
   Configurable via environment variable:  
   `PEPP_GOOGLE_SERVICE_ACCOUNT_JSON` or `GOOGLE_SERVICE_ACCOUNT_JSON`  
   Or in `config/secrets.php` via:  
   `define('PEPP_GOOGLE_WORKSPACE_SA_KEY_PATH', '/path/to/key.json');`
2. **Exclusion from Git**:  
   `.gitignore` explicitly ignores `config/secrets/*.json`, `google-secrets/`, `*.pem`, `*.key`, and `*serviceaccount*.json`.
3. **No Credential Exposure**:  
   Private keys, JWT assertions, and Bearer access tokens are never logged, never rendered in HTML/JavaScript, never output to API responses, and never stored in database tables.
4. **Account Boundary Enforcement**:  
   The client rejects `meet@pepponline.in` at the code level, preventing cross-organization leakage.

---

## 4. Live Session Scheduling & Provisioning Workflow

### Architectural Redesign: Native Meet Space Ownership
Previously, Google Meet spaces were provisioned implicitly through Google Calendar using `conferenceData.createRequest`. Under Google's API authorization model, the OAuth scope `https://www.googleapis.com/auth/meetings.space.created` only grants management authority to meeting spaces **created directly by the application API client via `meet.googleapis.com/v2/spaces`**. Calendar-created spaces rejected `POST /v2/spaces/{space}/members` with `HTTP 403 Forbidden: Permission denied on resource MeetingSpace`.

To ensure 100% reliable Co-Host assignment, the architecture provisions the native Google Meet space **first**, establishes ownership, assigns and verifies the Co-Host, and then links that native space into the Google Calendar event.

```
ERP Live Session
    ↓
Native Meet Space Creation (POST /v2/spaces, accessType = OPEN)
    ↓
Configure Meet Space (moderation=ON, accessType=OPEN, autoRecording=ON)
    ↓
Assign Faculty COHOST (POST /v2/{space}/members with role=COHOST)
    ↓
Verify COHOST (GET /v2/{space}/members read-back verification)
    ↓
Create Calendar Event (Using existing native Meet space link, NO createRequest)
    ↓
Store Meet URI + Calendar Event ID
    ↓
Session Ready
```

### Detailed Lifecycle Steps:
1. **Validate ERP Session & Course Selections**: Ensures required topic, valid start/end datetime, and courses.
2. **Resolve Active Eligible Students**: Queries `users` where `status = 'approved' AND student_status = 'active'`, deduplicated by normalized email.
3. **Resolve Faculty**: Retrieves assigned faculty name and email address.
4. **Native Google Meet Space Creation (Step 1)**:  
   Calls `POST https://meet.googleapis.com/v2/spaces` using `admin@pepponline.in` DWD impersonation with `accessType = 'OPEN'`.  
   Reuses existing `google_meet_space_name` if retrying, ensuring duplicate spaces are never created.
5. **Configure Meet Space Settings (Step 2)**:  
   Configures `moderation = 'ON'` (Host Management), `accessType = 'OPEN'` (for all new Live Sessions), `attendanceReportGenerationType = 'GENERATE_REPORT'`, and `artifactConfig` (auto-recording, auto-transcription, smart notes).
   *Note: OPEN Meet access means the Meet link itself becomes the access credential. Anyone possessing the link may join without requiring their account to match the calendar invitation, while Host Management and Faculty Co-host controls remain strictly active. Existing sessions retain their existing configuration without retroactive modification.*
6. **Assign Faculty as Co-Host (Step 3)**:  
   Calls `POST /v2/{parent=spaces/*}/members` with `{ "email": faculty_email, "role": "COHOST" }`.
7. **Read-Back COHOST Verification (Step 4)**:  
   Calls `GET /v2/{parent=spaces/*}/members` and verifies the faculty is confirmed with `role = 'COHOST'`. If verification fails, the session is NOT marked as synced; the partial state and a safe diagnostic are recorded in `google_error_message`.
8. **Create Google Calendar Event (Step 5)**:  
   Creates the event linking the **native Meet space link** directly (via `conferenceData` with `conferenceSolution` + `entryPoints`, or `location` fallback).  
   **`conferenceData.createRequest` is NOT called**, preventing duplicate Meet spaces.  
   Sets `guestsCanSeeOtherGuests = false`, `sendUpdates = 'all'`, and reminder overrides.
9. **Record Invited Attendees**: Records all invited students into `session_attendance` with status `invited`.
10. **Commit Metadata**: Sets `google_integration_status = 'synced'` and stores `google_calendar_event_id`, `google_meet_space_name`, `google_meet_uri`, and `google_meet_code`.

> [!NOTE]
> Auto-artifact start with a COHOST joining while the primary organizer does not join is supported by Google Meet policy, but must be verified with a controlled production test after this architecture change.

---

## 4.1. Explicit End Live Session (`endActiveConference`)

When a faculty member simply closes their browser tab or leaves a meeting, the conference remains alive in Google's cloud infrastructure as long as other participants remain connected or until Google's idle timeout triggers (which can take several hours). During this period, recording, transcription, and smart notes finalization are delayed.

PEPP ERP provides an explicit **"End Live Session"** backend action:
* API: `POST https://meet.googleapis.com/v2/spaces/{space}:endActiveConference`
* Scope: `https://www.googleapis.com/auth/meetings.space.created`
* Behavior: Ejects all active participants, terminates the live conference, marks ERP session `status = 'completed'`, and triggers immediate attendance and artifact synchronization.

---

## 5. Guest Privacy & Calendar Reminder Limitations

### Student Guest Privacy
* When creating the Google Calendar event, `guestsCanSeeOtherGuests = false` is explicitly set.
* Student A cannot view Student B's email address or presence in Google Calendar or Google Meet.
* Frontend AJAX endpoints in the ERP omit student email addresses from attendee views.

### Calendar Reminder Behavior (Requirement 24)
* The requested reminder overrides (24 hours, 12 hours, 1 hour, 10 minutes, and at session start) are configured on the organizer's primary calendar event.
* **Google Calendar Limitation**: Google Calendar API does not allow event organizers to force reminder alarms or push popups onto attendees' personal devices. Attendees receive the standard Calendar invitation email and notifications governed by their own personal Google Calendar notification preferences.
* PEPP ERP does not generate duplicate reminder emails for Google-integrated sessions.

---

## 6. Multiple Simultaneous Meetings

* Under Google Workspace Business Plus, `admin@pepponline.in` can schedule multiple simultaneous calendar events at the same date and time (e.g., Session A at 10:00 AM, Session B at 10:00 AM).
* Each session creates a distinct event and requests its own unique Google Meet conference.
* Because the assigned faculty for each session is configured as a `COHOST`, the faculty can start and run the class without requiring `admin@pepponline.in` to attend concurrently.
* Each session generates independent conference records, participant sessions, attendance data, and recording artifacts.

---

## 7. Attendance Synchronization

Post-session attendance is retrieved using Google Meet REST API v2:
1. `GET https://meet.googleapis.com/v2/conferenceRecords?filter=space.name="spaces/{space}"`
2. `GET https://meet.googleapis.com/v2/{conferenceRecord}/participants`
3. `GET https://meet.googleapis.com/v2/{participant}/participantSessions`
4. Multiple join/leave sessions for a participant are aggregated:
   - Earliest `first_join_time`
   - Latest `last_leave_time`
   - Total duration in seconds (`total_duration_seconds`)
5. Matches participant to `users` table by normalized email (`status = 'approved' AND student_status = 'active'`).
6. Classifies attendance status against configurable threshold:
   - `full attendance`: $\ge 80\%$ of scheduled duration
   - `partial attendance`: $\ge$ threshold (default 50%)
   - `joined`: $> 0$ seconds
   - `absent`: invited students with 0 duration after session end
   - `unknown/unmatched`: participants without matching active ERP account
7. Upserts idempotently into `session_attendance`.

---

## 8. Artifacts Synchronization (Recordings, Transcripts, Smart Notes)

* Artifacts are fetched via Google Meet REST API v2:
  - `GET https://meet.googleapis.com/v2/{conferenceRecord}/recordings`
  - `GET https://meet.googleapis.com/v2/{conferenceRecord}/transcripts`
  - `GET https://meet.googleapis.com/v2/{conferenceRecord}/smartNotes`
* Recordings (.mp4) and Transcripts (.docs) reside in `admin@pepponline.in` Google Drive.
* ERP stores Drive file IDs, export URIs, and states in `session_google_artifacts`.
* **Zero Video Download**: Video files are never downloaded to Hostinger server disk.

---

## 9. Error Recovery & Idempotency

* **Atomic Database State**: If Google provisioning encounters an API or network failure, the ERP session is preserved with `google_integration_status = 'failed'` and the error is captured in `google_error_message`.
* **Admin Retry Button**: The admin session table displays a "Retry Google Meet Provisioning" button.
* **Idempotent Retry**: Re-running provisioning checks existing `google_calendar_event_id` and unique `requestId` to avoid creating duplicate calendar events or duplicate spaces.
* **Member / Cohost Safety**: Adding a member returns 409 Conflict if already present, which is handled gracefully as success.

---

## 10. Database Schema Reference

### `sessions` table additions:
- `google_integrated` TINYINT(1) DEFAULT 0
- `google_calendar_event_id` VARCHAR(255) DEFAULT NULL
- `google_calendar_id` VARCHAR(255) DEFAULT 'primary'
- `google_meet_space_name` VARCHAR(255) DEFAULT NULL
- `google_meet_uri` VARCHAR(500) DEFAULT NULL
- `google_meet_code` VARCHAR(50) DEFAULT NULL
- `google_integration_status` VARCHAR(50) DEFAULT NULL
- `google_last_sync_at` DATETIME DEFAULT NULL
- `google_error_message` TEXT DEFAULT NULL

### `session_attendance` table:
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `session_id` INT NOT NULL
- `user_id` VARCHAR(50) DEFAULT NULL
- `google_participant_name` VARCHAR(255) DEFAULT NULL
- `google_participant_email` VARCHAR(190) DEFAULT NULL
- `google_participant_resource` VARCHAR(255) DEFAULT NULL
- `first_join_time` DATETIME DEFAULT NULL
- `last_leave_time` DATETIME DEFAULT NULL
- `total_duration_seconds` INT NOT NULL DEFAULT 0
- `attendance_status` ENUM('invited','joined','partial attendance','full attendance','absent','unknown/unmatched')
- `sync_status` VARCHAR(50) DEFAULT 'synced'
- `google_conference_record` VARCHAR(255) DEFAULT NULL
- `google_participant_session` TEXT DEFAULT NULL
- UNIQUE KEY (`session_id`, `google_participant_email`)

### `session_google_artifacts` table:
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `session_id` INT NOT NULL
- `artifact_type` ENUM('recording','transcript','smart_notes') NOT NULL
- `google_resource_name` VARCHAR(255) NOT NULL
- `drive_file_id` VARCHAR(255) DEFAULT NULL
- `artifact_state` VARCHAR(50) DEFAULT 'active'
- `artifact_url` VARCHAR(500) DEFAULT NULL
- UNIQUE KEY (`session_id`, `artifact_type`, `google_resource_name`)
