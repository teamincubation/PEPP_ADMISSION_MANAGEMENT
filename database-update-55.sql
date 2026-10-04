-- ============================================================================
-- PEPP Learning ERP — Database Update 55
-- Faculty Live Session Instructions & Multi-Language WhatsApp Notification Workflow
-- ============================================================================
--
-- PURPOSE:
-- 1. Creates `faculty_session_instructions` table:
--      Database-driven multi-language instructions for faculty live sessions
--      (English, Malayalam seeded by default; Hindi, Tamil, Kannada, Arabic, etc. supported)
--      with maximum 1024 character body length enforcement.
-- 2. Creates `faculty_session_acknowledgements` table:
--      Tracks faculty acknowledgement of live session instructions with idempotent unique index.
-- 3. Creates `faculty_session_interactions` table:
--      Stores temporary secure interaction tokens and state for inbound WhatsApp webhook
--      language selection and confirmation (anti-IDOR protection).
-- 4. Extends `communication_queue` table with `idempotency_key` column:
--      Guarantees strict deduplication and idempotency for scheduled session notifications.
-- 5. Seeds faculty live session WhatsApp templates in `communication_templates`:
--      - faculty_session_scheduled
--      - faculty_session_reminder
--      - faculty_session_start
--      - faculty_session_start_now
--      - faculty_session_cancelled
-- 6. Seeds faculty live session event mappings in `communication_event_mappings`.
--
-- IDEMPOTENCY & SAFETY GUARANTEES:
-- - All operations execute via safe conditional checks against `information_schema`.
-- - No tables, columns, indexes, or existing records are ever removed or wiped.
-- - Existing communication workflows remain 100% backward compatible.
-- - Safe to re-run multiple times on MySQL/MariaDB with zero errors.
--
-- IMPORTANT:
-- - DO NOT run this migration automatically on production without authorization.
-- - The application codebase contains self-healing fallbacks that function safely
--   both before and after this migration is executed.
-- ============================================================================

DROP PROCEDURE IF EXISTS MigrateFacultyLiveSessionWorkflow55;
DELIMITER //
CREATE PROCEDURE MigrateFacultyLiveSessionWorkflow55()
BEGIN
    -- ── 1. CREATE faculty_session_instructions TABLE ─────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'faculty_session_instructions'
    ) THEN
        CREATE TABLE `faculty_session_instructions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `language_code` VARCHAR(20) NOT NULL UNIQUE,
            `language_name` VARCHAR(100) NOT NULL,
            `instruction_title` VARCHAR(255) NOT NULL,
            `instruction_body` VARCHAR(1024) NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_fsi_active` (`active`),
            KEY `idx_fsi_lang` (`language_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 2. CREATE faculty_session_acknowledgements TABLE ─────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'faculty_session_acknowledgements'
    ) THEN
        CREATE TABLE `faculty_session_acknowledgements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` INT NOT NULL,
            `faculty_id` INT NOT NULL,
            `instruction_language` VARCHAR(20) NOT NULL,
            `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `wa_message_id` VARCHAR(150) DEFAULT NULL,
            UNIQUE KEY `uniq_fac_sess_ack` (`session_id`, `faculty_id`),
            KEY `idx_fsa_faculty` (`faculty_id`),
            KEY `idx_fsa_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 3. CREATE faculty_session_interactions TABLE ─────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'faculty_session_interactions'
    ) THEN
        CREATE TABLE `faculty_session_interactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `interaction_token` VARCHAR(64) NOT NULL UNIQUE,
            `faculty_id` INT NOT NULL,
            `session_id` INT NOT NULL,
            `phone` VARCHAR(30) NOT NULL,
            `originating_wa_message_id` VARCHAR(150) DEFAULT NULL,
            `selected_language` VARCHAR(20) DEFAULT NULL,
            `step` VARCHAR(50) NOT NULL DEFAULT 'awaiting_language',
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_fsi_phone` (`phone`),
            KEY `idx_fsi_token` (`interaction_token`),
            KEY `idx_fsi_session` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- ── 4. EXTEND communication_queue WITH idempotency_key ───────────────────
    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'communication_queue'
    ) THEN
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'communication_queue' AND column_name = 'idempotency_key'
        ) THEN
            ALTER TABLE `communication_queue`
            ADD COLUMN `idempotency_key` VARCHAR(150) DEFAULT NULL AFTER `invoice_id`;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = 'communication_queue' AND index_name = 'idx_cq_idempotency_key'
        ) THEN
            ALTER TABLE `communication_queue`
            ADD UNIQUE KEY `idx_cq_idempotency_key` (`idempotency_key`);
        END IF;
    END IF;

END //
DELIMITER ;

CALL MigrateFacultyLiveSessionWorkflow55();
DROP PROCEDURE IF EXISTS MigrateFacultyLiveSessionWorkflow55;

-- ── 5. SEED DEFAULT INSTRUCTION RECORDS (ENGLISH & MALAYALAM) ────────────────
INSERT INTO `faculty_session_instructions` (`language_code`, `language_name`, `instruction_title`, `instruction_body`, `active`)
VALUES
(
    'en',
    'English',
    'PEPP Live Session – Faculty Instructions',
    'PEPP Live Session – Faculty Instructions\n\nPlease join the session on time. Recording and Gemini meeting notes may start automatically when you enter the session, so please do not join earlier than 5 minutes before the scheduled time to avoid unnecessary recording length.\n\nBefore the session, please check your camera, microphone, presentation and overall audio/video quality with the PEPP Admin using the separate test link provided by the Admin. Do not use the live session link for testing.\n\nPlease ensure proper lighting, stable internet connectivity and a suitable environment for conducting the session.\n\nPlease follow the proposed session duration. If you need to cancel or postpone the session, inform the PEPP Admin at least 3 hours before the scheduled start time.\n\nWhen you finish the session, please stop the recording using the Google Meet options (three-dot menu). This is mandatory. If the recording does not stop automatically after you leave, rejoin the session using the same link and stop the recording manually.\n\nThank you for your cooperation and for ensuring a smooth learning experience for our students.',
    1
),
(
    'ml',
    'Malayalam',
    'PEPP Live Session – Faculty Instructions',
    'PEPP Live Session – Faculty Instructions\n\nദയവായി നിശ്ചയിച്ച സമയത്ത് തന്നെ session-ൽ join ചെയ്യുക. നിങ്ങൾ session-ൽ പ്രവേശിക്കുമ്പോൾ recording-ഉം Gemini meeting notes-ഉം സ്വയമേവ ആരംഭിക്കാം. അതിനാൽ scheduled time-ന് 5 മിനിറ്റിൽ കൂടുതൽ മുമ്പ് session-ൽ join ചെയ്യരുത്. ഇത് recording അനാവശ്യമായി ദൈർഘ്യമാകുന്നത് ഒഴിവാക്കാൻ സഹായിക്കും.\n\nSession ആരംഭിക്കുന്നതിന് മുമ്പ് camera, microphone, presentation, audio/video quality എന്നിവ PEPP Admin-നൊപ്പം പ്രത്യേകമായി നൽകുന്ന test link ഉപയോഗിച്ച് പരിശോധിക്കുക. Live session link testing-ന് ഉപയോഗിക്കരുത്.\n\nമതിയായ lighting, stable internet connection, അനുയോജ്യമായ teaching environment എന്നിവ ഉറപ്പാക്കുക.\n\nനിർദ്ദേശിച്ചിരിക്കുന്ന session duration പാലിക്കുക. Session cancel ചെയ്യുകയോ postpone ചെയ്യുകയോ ചെയ്യേണ്ട സാഹചര്യമുണ്ടെങ്കിൽ scheduled time-ന് കുറഞ്ഞത് 3 മണിക്കൂർ മുമ്പ് PEPP Admin-നെ അറിയിക്കുക.\n\nSession അവസാനിപ്പിക്കുമ്പോൾ Google Meet-ലെ three-dot menu ഉപയോഗിച്ച് recording stop ചെയ്യുക. ഇത് നിർബന്ധമാണ്. നിങ്ങൾ session-ൽ നിന്ന് പുറത്തുപോയതിന് ശേഷം recording സ്വമേധയാ stop ആയിട്ടില്ലെങ്കിൽ, അതേ link ഉപയോഗിച്ച് വീണ്ടും join ചെയ്ത് recording manually stop ചെയ്യുക.\n\nവിദ്യാർത്ഥികൾക്ക് മികച്ച learning experience നൽകുന്നതിനായി നിങ്ങളുടെ സഹകരണത്തിന് നന്ദി.',
    1
)
ON DUPLICATE KEY UPDATE
    `language_name` = VALUES(`language_name`),
    `instruction_title` = VALUES(`instruction_title`),
    `active` = 1;

-- ── 6. SEED FACULTY SESSION TEMPLATES IN communication_templates ─────────────
-- Template 1: faculty_session_scheduled
INSERT INTO `communication_templates` (`channel`, `template_name`, `language`, `status`, `category`, `quality_status`, `meta_data`)
VALUES (
    'whatsapp',
    'faculty_session_scheduled',
    'en',
    'approved',
    'utility',
    'green',
    JSON_OBJECT(
        'name', 'faculty_session_scheduled',
        'category', 'UTILITY',
        'components', JSON_ARRAY(
            JSON_OBJECT(
                'type', 'BODY',
                'text', 'Hello {{1}},\n\nYou have been scheduled for a PEPP Live Session.\n\nSession: {{2}}\nType: {{3}}\nDate & Time: {{4}}\nCourses: {{5}}\nDuration: {{6}}\n\nPlease review the faculty instructions before the session. You are requested to join on time and not earlier than 5 minutes before the scheduled start.',
                'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Dr. Ananya Sharma', 'Clinical Neuropsychology', 'Live', '20 Oct 2026, 10:00 AM', 'M. Clin. Psy.', '1 hour')))
            ),
            JSON_OBJECT(
                'type', 'BUTTONS',
                'buttons', JSON_ARRAY(
                    JSON_OBJECT('type', 'QUICK_REPLY', 'text', 'Read Instructions')
                )
            )
        ),
        'buttons', JSON_OBJECT(
            'quick_reply', JSON_ARRAY(
                JSON_OBJECT('text', 'Read Instructions', 'payload', 'READ_INSTRUCTIONS', 'action_type', 'INBOUND_FLOW')
            )
        )
    )
)
ON DUPLICATE KEY UPDATE
    `category` = 'utility',
    `status` = 'approved';

-- Template 2: faculty_session_reminder
INSERT INTO `communication_templates` (`channel`, `template_name`, `language`, `status`, `category`, `quality_status`, `meta_data`)
VALUES (
    'whatsapp',
    'faculty_session_reminder',
    'en',
    'approved',
    'utility',
    'green',
    JSON_OBJECT(
        'name', 'faculty_session_reminder',
        'category', 'UTILITY',
        'components', JSON_ARRAY(
            JSON_OBJECT(
                'type', 'BODY',
                'text', 'Hello {{1}},\n\nThis is a reminder that you have a PEPP Live Session scheduled today.\n\nSession: {{2}}\nTime: {{3}}\nDuration: {{4}}\n\nWe hope you are prepared well for the session.\n\nPlease ensure that your presentation, audio/video setup and internet connection are ready before the scheduled time.\n\nThank you!',
                'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Dr. Ananya Sharma', 'Clinical Neuropsychology', '10:00 AM', '1 hour')))
            )
        )
    )
)
ON DUPLICATE KEY UPDATE
    `category` = 'utility',
    `status` = 'approved';

-- Template 3: faculty_session_start
INSERT INTO `communication_templates` (`channel`, `template_name`, `language`, `status`, `category`, `quality_status`, `meta_data`)
VALUES (
    'whatsapp',
    'faculty_session_start',
    'en',
    'approved',
    'utility',
    'green',
    JSON_OBJECT(
        'name', 'faculty_session_start',
        'category', 'UTILITY',
        'components', JSON_ARRAY(
            JSON_OBJECT(
                'type', 'BODY',
                'text', 'Hello {{1}},\n\nYour PEPP Live Session starts in approximately 1 hour.\n\nSession: {{2}}\nDate & Time: {{3}}\nDuration: {{4}}\n\nYour Google Meet session link is ready.\n\nImportant: Recording and Gemini meeting notes will start automatically when you enter the session. Please do not enter earlier than 5 minutes before the scheduled start time.',
                'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Dr. Ananya Sharma', 'Clinical Neuropsychology', '20 Oct 2026, 10:00 AM', '1 hour')))
            ),
            JSON_OBJECT(
                'type', 'BUTTONS',
                'buttons', JSON_ARRAY(
                    JSON_OBJECT(
                        'type', 'URL',
                        'text', 'Start Live',
                        'url', 'https://meet.google.com/{{1}}',
                        'example', JSON_ARRAY('abc-defg-hij')
                    )
                )
            )
        )
    )
)
ON DUPLICATE KEY UPDATE
    `category` = 'utility',
    `status` = 'approved';

-- Template 4: faculty_session_start_now
INSERT INTO `communication_templates` (`channel`, `template_name`, `language`, `status`, `category`, `quality_status`, `meta_data`)
VALUES (
    'whatsapp',
    'faculty_session_start_now',
    'en',
    'approved',
    'utility',
    'green',
    JSON_OBJECT(
        'name', 'faculty_session_start_now',
        'category', 'UTILITY',
        'components', JSON_ARRAY(
            JSON_OBJECT(
                'type', 'BODY',
                'text', 'Hello {{1}},\n\nYour PEPP Live Session is starting now.\n\nSession: {{2}}\n\nPlease join using the button below.\n\nReminder: Recording and Gemini meeting notes will start automatically when you enter the session. Please join only now and not earlier.',
                'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Dr. Ananya Sharma', 'Clinical Neuropsychology')))
            ),
            JSON_OBJECT(
                'type', 'BUTTONS',
                'buttons', JSON_ARRAY(
                    JSON_OBJECT(
                        'type', 'URL',
                        'text', 'Start Now',
                        'url', 'https://meet.google.com/{{1}}',
                        'example', JSON_ARRAY('abc-defg-hij')
                    )
                )
            )
        )
    )
)
ON DUPLICATE KEY UPDATE
    `category` = 'utility',
    `status` = 'approved';

-- Template 5: faculty_session_cancelled
INSERT INTO `communication_templates` (`channel`, `template_name`, `language`, `status`, `category`, `quality_status`, `meta_data`)
VALUES (
    'whatsapp',
    'faculty_session_cancelled',
    'en',
    'approved',
    'utility',
    'green',
    JSON_OBJECT(
        'name', 'faculty_session_cancelled',
        'category', 'UTILITY',
        'components', JSON_ARRAY(
            JSON_OBJECT(
                'type', 'BODY',
                'text', 'Hello {{1}},\n\nYour PEPP Live Session scheduled for:\n\n{{2}}\n\nSession: {{3}}\n\nhas been cancelled by the PEPP Admin.\n\nPlease do not use the previously shared session link.\n\nIf a new schedule is confirmed, you will receive a separate notification.\n\nThank you.',
                'example', JSON_OBJECT('body_text', JSON_ARRAY(JSON_ARRAY('Dr. Ananya Sharma', '20 Oct 2026, 10:00 AM', 'Clinical Neuropsychology')))
            )
        )
    )
)
ON DUPLICATE KEY UPDATE
    `category` = 'utility',
    `status` = 'approved';

-- ── 7. SEED EVENT MAPPINGS IN communication_event_mappings ──────────────────
INSERT INTO `communication_event_mappings` (`event_name`, `template_name`, `parameter_mappings`)
VALUES
(
    'faculty_session_scheduled',
    'faculty_session_scheduled',
    JSON_OBJECT(
        '1', JSON_OBJECT('type', 'variable', 'value', 'faculty_name'),
        '2', JSON_OBJECT('type', 'variable', 'value', 'session_topic'),
        '3', JSON_OBJECT('type', 'variable', 'value', 'session_type'),
        '4', JSON_OBJECT('type', 'variable', 'value', 'session_datetime'),
        '5', JSON_OBJECT('type', 'variable', 'value', 'session_courses'),
        '6', JSON_OBJECT('type', 'variable', 'value', 'session_duration')
    )
),
(
    'faculty_session_reminder',
    'faculty_session_reminder',
    JSON_OBJECT(
        '1', JSON_OBJECT('type', 'variable', 'value', 'faculty_name'),
        '2', JSON_OBJECT('type', 'variable', 'value', 'session_topic'),
        '3', JSON_OBJECT('type', 'variable', 'value', 'session_datetime'),
        '4', JSON_OBJECT('type', 'variable', 'value', 'session_duration')
    )
),
(
    'faculty_session_start',
    'faculty_session_start',
    JSON_OBJECT(
        '1', JSON_OBJECT('type', 'variable', 'value', 'faculty_name'),
        '2', JSON_OBJECT('type', 'variable', 'value', 'session_topic'),
        '3', JSON_OBJECT('type', 'variable', 'value', 'session_datetime'),
        '4', JSON_OBJECT('type', 'variable', 'value', 'session_duration')
    )
),
(
    'faculty_session_start_now',
    'faculty_session_start_now',
    JSON_OBJECT(
        '1', JSON_OBJECT('type', 'variable', 'value', 'faculty_name'),
        '2', JSON_OBJECT('type', 'variable', 'value', 'session_topic')
    )
),
(
    'faculty_session_cancelled',
    'faculty_session_cancelled',
    JSON_OBJECT(
        '1', JSON_OBJECT('type', 'variable', 'value', 'faculty_name'),
        '2', JSON_OBJECT('type', 'variable', 'value', 'session_datetime'),
        '3', JSON_OBJECT('type', 'variable', 'value', 'session_topic')
    )
)
ON DUPLICATE KEY UPDATE
    `template_name` = VALUES(`template_name`),
    `parameter_mappings` = VALUES(`parameter_mappings`);
