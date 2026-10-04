<?php
/**
 * FacultySessionInteractionHandler.php
 * Handles inbound WhatsApp interactions for Faculty Live Session instructions:
 * - "Read Instructions" -> Interactive Language List
 * - Language selection -> Dynamic Instruction body (max 1024 chars) + Quick Reply "Read & Confirm"
 * - "Read & Confirm" -> Idempotent Acknowledgement record + Confirmation message
 *
 * Implements strict Anti-IDOR and ownership verification against sender phone number.
 */

declare(strict_types=1);

require_once __DIR__ . '/CommunicationEngine.php';
require_once __DIR__ . '/CommunicationHelper.php';

class FacultySessionInteractionHandler {
    private PDO $pdo;
    private CommunicationEngine $engine;

    public function __construct(PDO $pdo, ?CommunicationEngine $engine = null) {
        $this->pdo = $pdo;
        $this->engine = $engine ?: CommunicationEngine::getInstance($pdo);
    }

    /**
     * Alias for handle()
     */
    public function handleInboundMessage(array $inboundMsg): ?array {
        return $this->handle($inboundMsg);
    }

    /**
     * Entry point to inspect and handle inbound messages for faculty session workflows.
     *
     * @param array $inboundMsg Parsed inbound message from webhook
     * @return array|null Null if not a faculty session interaction, or result array if handled
     */
    public function handle(array $inboundMsg): ?array {
        $from = trim((string)($inboundMsg['from'] ?? ''));
        $cleanFrom = preg_replace('/\D/', '', $from);
        if (empty($cleanFrom)) {
            return null;
        }

        $type = $inboundMsg['type'] ?? 'text';
        $msgId = (string)($inboundMsg['id'] ?? '');
        $replyToId = (string)($inboundMsg['context']['id'] ?? $inboundMsg['context']['message_id'] ?? '');

        // Determine action text & payload
        $buttonPayload = '';
        $buttonText = '';
        $listReplyId = '';
        $rawText = '';

        if ($type === 'button') {
            $buttonPayload = trim((string)($inboundMsg['button']['payload'] ?? ''));
            $buttonText = trim((string)($inboundMsg['button']['text'] ?? ''));
        } elseif ($type === 'interactive') {
            $intType = $inboundMsg['interactive']['type'] ?? '';
            if ($intType === 'button_reply') {
                $buttonPayload = trim((string)($inboundMsg['interactive']['button_reply']['id'] ?? ''));
                $buttonText = trim((string)($inboundMsg['interactive']['button_reply']['title'] ?? ''));
            } elseif ($intType === 'list_reply') {
                $listReplyId = trim((string)($inboundMsg['interactive']['list_reply']['id'] ?? ''));
            }
        } elseif ($type === 'text') {
            $rawText = trim((string)($inboundMsg['text']['body'] ?? ''));
        }

        // ── SCENARIO 1: Faculty Taps "Read Instructions" (Phase 4) ────────────
        $isReadInstructions = (
            strtoupper($buttonPayload) === 'READ_INSTRUCTIONS'
            || stripos($buttonText, 'Read Instructions') !== false
            || stripos($rawText, 'Read Instructions') !== false
        );

        if ($isReadInstructions) {
            return $this->handleReadInstructionsRequest($cleanFrom, $msgId, $replyToId);
        }

        // ── SCENARIO 2: Faculty Selects Language from Interactive List (Phase 5)
        if (!empty($listReplyId) && str_starts_with($listReplyId, 'fsi_lang_')) {
            return $this->handleLanguageSelection($cleanFrom, $listReplyId, $msgId);
        }

        // ── SCENARIO 3: Faculty Taps "Read & Confirm" (Phase 6) ───────────────
        $isConfirmClick = (
            str_starts_with($buttonPayload, 'confirm_')
            || stripos($buttonText, 'Read & Confirm') !== false
            || stripos($buttonText, 'Read and Confirm') !== false
            || stripos($rawText, 'Read & Confirm') !== false
        );

        if ($isConfirmClick) {
            return $this->handleReadAndConfirm($cleanFrom, $buttonPayload, $msgId);
        }

        return null;
    }

    /**
     * Handles "Read Instructions" button click:
     * Validates faculty, discovers associated live session, initializes interaction token,
     * and sends an interactive LIST message with active languages.
     */
    public function handleReadInstructionsRequest(string $cleanFrom, string $msgId, string $replyToId): array {
        $faculty = $this->resolveFacultyByPhone($cleanFrom);
        if (!$faculty) {
            return ['handled' => false, 'error' => 'Sender is not a registered faculty member.'];
        }

        $facultyId = (int)$faculty['id'];
        $session = $this->resolveSessionForFaculty($facultyId, $replyToId);
        if (!$session) {
            return ['handled' => false, 'error' => 'No upcoming or active session found for faculty.'];
        }

        $sessionId = (int)$session['id'];

        // Self-heal table in dev/testing if needed
        $this->ensureTablesExist();

        // Generate secure 32-character random interaction token
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24-hour expiry

        // Store secure interaction context
        $stmtIns = $this->pdo->prepare("
            INSERT INTO faculty_session_interactions
            (interaction_token, faculty_id, session_id, phone, originating_wa_message_id, step, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'awaiting_language', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmtIns->execute([$token, $facultyId, $sessionId, $cleanFrom, $msgId, $expiresAt]);

        // Retrieve active languages
        $languages = $this->getActiveLanguages();

        // Build Meta Interactive List options (max 10)
        $rows = [];
        foreach (array_slice($languages, 0, 10) as $lang) {
            $langCode = $lang['language_code'];
            $langName = $lang['language_name'];
            $rows[] = [
                'id'          => "fsi_lang_{$langCode}_{$token}",
                'title'       => substr($langName, 0, 24),
                'description' => substr("Read instructions in {$langName}", 0, 72)
            ];
        }

        $interactiveTemplate = [
            'type'                      => 'interactive',
            'interactive_type'          => 'list',
            'interactive_header'        => 'PEPP Live Session',
            'interactive_body'          => 'Please select your preferred language to read the faculty instructions.',
            'interactive_footer'        => 'PEPP Learning',
            'interactive_button_text'   => 'Choose Language',
            'interactive_sections'      => [
                [
                    'title' => 'Available Languages',
                    'rows'  => $rows
                ]
            ]
        ];

        // Send via provider
        $provider = $this->engine->getProvider('whatsapp');
        $sendRes = $provider->sendMessage(
            $cleanFrom,
            'Faculty Instructions Language Selection',
            '',
            'Please select your preferred language to read the faculty instructions.',
            [],
            $interactiveTemplate
        );

        return [
            'handled'           => true,
            'action'            => 'language_list_sent',
            'faculty_id'        => $facultyId,
            'session_id'        => $sessionId,
            'interaction_token' => $token,
            'languages_count'   => count($rows),
            'send_result'       => $sendRes
        ];
    }

    /**
     * Handles Language Selection from Interactive List (Phase 5):
     * Validates interaction token, sender phone, and language, retrieves instruction body,
     * and sends instruction text with Quick Reply "Read & Confirm".
     */
    public function handleLanguageSelection(string $cleanFrom, string $listReplyId, string $msgId): array {
        // ID format: fsi_lang_{langCode}_{token}
        if (!preg_match('/^fsi_lang_([a-zA-Z0-9_\-]+)_([a-f0-9]{32})$/', $listReplyId, $matches)) {
            return ['handled' => false, 'error' => 'Malformed language selection identifier.'];
        }

        $langCode = $matches[1];
        $token = $matches[2];

        $this->ensureTablesExist();

        // 1. Validate interaction context exists and is not expired
        $stmtCtx = $this->pdo->prepare("
            SELECT * FROM faculty_session_interactions
            WHERE interaction_token = ? AND expires_at > CURRENT_TIMESTAMP
            LIMIT 1
        ");
        $stmtCtx->execute([$token]);
        $interaction = $stmtCtx->fetch(PDO::FETCH_ASSOC);

        if (!$interaction) {
            return ['handled' => false, 'error' => 'Invalid or expired interaction session.'];
        }

        // 2. Validate Anti-IDOR: phone must strictly match sender phone
        $ctxPhone = preg_replace('/\D/', '', (string)$interaction['phone']);
        if ($ctxPhone !== $cleanFrom && substr($cleanFrom, -10) !== substr($ctxPhone, -10)) {
            return ['handled' => false, 'error' => 'Security Error: Interaction context does not belong to sender phone (IDOR blocked).'];
        }

        $facultyId = (int)$interaction['faculty_id'];
        $sessionId = (int)$interaction['session_id'];

        // 3. Validate selected language exists and is active
        $instruction = $this->getInstructionByLanguage($langCode);
        if (!$instruction) {
            return ['handled' => false, 'error' => "Selected language '{$langCode}' is not active or available."];
        }

        // 4. Update interaction state
        $stmtUp = $this->pdo->prepare("
            UPDATE faculty_session_interactions
            SET selected_language = ?, step = 'awaiting_confirmation', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmtUp->execute([$langCode, $interaction['id']]);

        // 5. Send Instruction Body (Enforcing <= 1024 chars) + Quick Reply "Read & Confirm"
        $title = trim((string)$instruction['instruction_title']);
        $body = trim((string)$instruction['instruction_body']);
        if (mb_strlen($body) > 1024) {
            $body = mb_substr($body, 0, 1021) . '...';
        }

        $fullInstructionText = "*{$title}*\n\n{$body}";
        // Ensure overall text length adheres to WhatsApp limit
        if (mb_strlen($fullInstructionText) > 1024) {
            $fullInstructionText = mb_substr($fullInstructionText, 0, 1021) . '...';
        }

        $confirmButtonId = "confirm_{$token}";
        $buttonTemplate = [
            'type'                  => 'interactive',
            'interactive_type'      => 'button',
            'interactive_body'      => $fullInstructionText,
            'interactive_footer'    => 'PEPP Learning',
            'interactive_buttons'   => [
                [
                    'type'  => 'reply',
                    'reply' => [
                        'id'    => $confirmButtonId,
                        'title' => 'Read & Confirm'
                    ]
                ]
            ]
        ];

        $provider = $this->engine->getProvider('whatsapp');
        $sendRes = $provider->sendMessage(
            $cleanFrom,
            $title,
            '',
            $fullInstructionText,
            [],
            $buttonTemplate
        );

        return [
            'handled'           => true,
            'action'            => 'instruction_sent',
            'faculty_id'        => $facultyId,
            'session_id'        => $sessionId,
            'language_code'     => $langCode,
            'interaction_token' => $token,
            'body'              => $fullInstructionText,
            'instruction_text'  => $fullInstructionText,
            'buttons'           => [
                ['text' => 'Read & Confirm', 'payload' => $confirmButtonId]
            ],
            'send_result'       => $sendRes
        ];
    }

    /**
     * Handles "Read & Confirm" button click (Phase 6):
     * Records acknowledgement idempotently and dispatches confirmation response exactly once.
     */
    public function handleReadAndConfirm(string $cleanFrom, string $buttonPayload, string $msgId): array {
        $this->ensureTablesExist();

        $token = '';
        if (preg_match('/^confirm_([a-f0-9]{32})$/', $buttonPayload, $m)) {
            $token = $m[1];
        }

        $interaction = null;
        if (!empty($token)) {
            $stmtCtx = $this->pdo->prepare("
                SELECT * FROM faculty_session_interactions
                WHERE interaction_token = ? AND expires_at > CURRENT_TIMESTAMP
                LIMIT 1
            ");
            $stmtCtx->execute([$token]);
            $interaction = $stmtCtx->fetch(PDO::FETCH_ASSOC);
        }

        // Fallback: match by phone and awaiting_confirmation step
        if (!$interaction) {
            $stmtFb = $this->pdo->prepare("
                SELECT * FROM faculty_session_interactions
                WHERE phone LIKE ? AND step IN ('awaiting_confirmation', 'awaiting_language') AND expires_at > CURRENT_TIMESTAMP
                ORDER BY id DESC LIMIT 1
            ");
            $stmtFb->execute(['%' . substr($cleanFrom, -10)]);
            $interaction = $stmtFb->fetch(PDO::FETCH_ASSOC);
        }

        if (!$interaction) {
            return ['handled' => false, 'error' => 'No active interaction session found for confirmation.'];
        }

        // Anti-IDOR validation
        $ctxPhone = preg_replace('/\D/', '', (string)$interaction['phone']);
        if ($ctxPhone !== $cleanFrom && substr($cleanFrom, -10) !== substr($ctxPhone, -10)) {
            return ['handled' => false, 'error' => 'Security Error: Interaction context does not belong to sender (IDOR blocked).'];
        }

        $facultyId = (int)$interaction['faculty_id'];
        $sessionId = (int)$interaction['session_id'];
        $langCode = trim((string)($interaction['selected_language'] ?: 'en'));

        // ── IDEMPOTENCY CHECK (Phase 6) ──────────────────────────────────────
        // Multiple clicks must not create duplicate acknowledgement records or duplicate confirmation messages.
        $stmtCheck = $this->pdo->prepare("
            SELECT id, acknowledged_at FROM faculty_session_acknowledgements
            WHERE session_id = ? AND faculty_id = ?
            LIMIT 1
        ");
        $stmtCheck->execute([$sessionId, $facultyId]);
        $existingAck = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existingAck) {
            // Already acknowledged! Suppress duplicate confirmation message and record creation.
            return [
                'handled'         => true,
                'action'          => 'duplicate_suppressed',
                'faculty_id'      => $facultyId,
                'session_id'      => $sessionId,
                'acknowledged_at' => $existingAck['acknowledged_at'],
                'message'         => 'Duplicate confirmation click handled idempotently without re-sending.'
            ];
        }

        // Insert unique acknowledgement record
        $stmtInsAck = $this->pdo->prepare("
            INSERT INTO faculty_session_acknowledgements
            (session_id, faculty_id, instruction_language, acknowledged_at, wa_message_id)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)
        ");
        $stmtInsAck->execute([$sessionId, $facultyId, $langCode, $msgId]);

        // Mark interaction step as confirmed
        $stmtUp = $this->pdo->prepare("
            UPDATE faculty_session_interactions
            SET step = 'confirmed', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmtUp->execute([$interaction['id']]);

        // Fetch faculty name for personalization
        $stmtFac = $this->pdo->prepare("SELECT name FROM faculties WHERE id = ?");
        $stmtFac->execute([$facultyId]);
        $facName = trim((string)$stmtFac->fetchColumn()) ?: 'Faculty';

        // Confirmation message text from Phase 6 specification:
        $confirmText = "Thank you, {$facName}. Your session instructions have been acknowledged.\n\nWe will send your session link approximately 1 hour before the scheduled session. You can also check the session details through your registered email address.";

        $provider = $this->engine->getProvider('whatsapp');
        $sendRes = $provider->sendMessage($cleanFrom, 'Instructions Acknowledged', '', $confirmText);

        return [
            'handled'           => true,
            'action'            => 'confirmed',
            'faculty_id'        => $facultyId,
            'session_id'        => $sessionId,
            'language'          => $langCode,
            'faculty_name'      => $facName,
            'body'              => $confirmText,
            'confirmation_text' => $confirmText,
            'send_result'       => $sendRes
        ];
    }

    /**
     * Resolves faculty row by phone number.
     */
    public function resolveFacultyByPhone(string $cleanPhone): ?array {
        $last10 = substr($cleanPhone, -10);
        $stmt = $this->pdo->prepare("
            SELECT id, name, mobile, email
            FROM faculties
            WHERE mobile LIKE ? AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['%' . $last10]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $rowPhone = preg_replace('/\D/', '', (string)$row['mobile']);
            if ($rowPhone === $cleanPhone || substr($rowPhone, -10) === $last10) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Resolves session for a faculty.
     * Prefers session linked via replyToId queue message; falls back to upcoming/active live session.
     */
    public function resolveSessionForFaculty(int $facultyId, string $replyToId = ''): ?array {
        // If replyToId provided, check communication_queue
        if (!empty($replyToId)) {
            $stmtQ = $this->pdo->prepare("
                SELECT idempotency_key, error_message, template_data
                FROM communication_queue
                WHERE message_id = ?
                LIMIT 1
            ");
            $stmtQ->execute([$replyToId]);
            $qRow = $stmtQ->fetch(PDO::FETCH_ASSOC);
            if ($qRow) {
                $idem = (string)($qRow['idempotency_key'] ?? '');
                if (preg_match('/session:(\d+)/', $idem, $m)) {
                    $sId = (int)$m[1];
                    $stmtS = $this->pdo->prepare("SELECT * FROM sessions WHERE id = ?");
                    $stmtS->execute([$sId]);
                    $sess = $stmtS->fetch(PDO::FETCH_ASSOC);
                    if ($sess) return $sess;
                }
            }
        }

        // Fallback: Find upcoming/active live session for faculty
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtUpcoming = $this->pdo->prepare("
                SELECT * FROM sessions
                WHERE faculty_id = ?
                  AND status = 'scheduled'
                  AND session_datetime >= datetime('now', '-2 hours')
                ORDER BY session_datetime ASC
                LIMIT 1
            ");
        } else {
            $stmtUpcoming = $this->pdo->prepare("
                SELECT * FROM sessions
                WHERE faculty_id = ?
                  AND status = 'scheduled'
                  AND session_datetime >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                ORDER BY session_datetime ASC
                LIMIT 1
            ");
        }
        $stmtUpcoming->execute([$facultyId]);
        $sess = $stmtUpcoming->fetch(PDO::FETCH_ASSOC);
        if ($sess) {
            return $sess;
        }

        // Fallback: any scheduled session for this faculty
        $stmtAny = $this->pdo->prepare("
            SELECT * FROM sessions
            WHERE faculty_id = ? AND status = 'scheduled'
            ORDER BY session_datetime DESC
            LIMIT 1
        ");
        $stmtAny->execute([$facultyId]);
        return $stmtAny->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retrieves active languages from faculty_session_instructions.
     */
    public function getActiveLanguages(): array {
        $this->ensureTablesExist();
        $col = 'active';
        try {
            $this->pdo->query("SELECT active FROM faculty_session_instructions LIMIT 0");
        } catch (Exception $e) {
            $col = 'is_active';
        }

        $stmt = $this->pdo->query("
            SELECT language_code, language_name, instruction_title, instruction_body
            FROM faculty_session_instructions
            WHERE {$col} = 1
            ORDER BY id ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // Seed defaults if empty
            $this->seedDefaultInstructions();
            $stmt = $this->pdo->query("
                SELECT language_code, language_name, instruction_title, instruction_body
                FROM faculty_session_instructions
                WHERE {$col} = 1
                ORDER BY id ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $rows ?: [];
    }

    /**
     * Retrieves instruction record for a given language code.
     */
    public function getInstructionByLanguage(string $langCode): ?array {
        $this->ensureTablesExist();
        $col = 'active';
        try {
            $this->pdo->query("SELECT active FROM faculty_session_instructions LIMIT 0");
        } catch (Exception $e) {
            $col = 'is_active';
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM faculty_session_instructions
            WHERE language_code = ? AND {$col} = 1
            LIMIT 1
        ");
        $stmt->execute([$langCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Ensures necessary database tables exist in dev / test / SQLite environments.
     */
    public function ensureTablesExist(): void {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $isSqlite = ($driver === 'sqlite');

            if ($isSqlite) {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS faculty_session_instructions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        language_code TEXT NOT NULL UNIQUE,
                        language_name TEXT NOT NULL,
                        instruction_title TEXT NOT NULL,
                        instruction_body TEXT NOT NULL,
                        active INTEGER NOT NULL DEFAULT 1,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE IF NOT EXISTS faculty_session_acknowledgements (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        session_id INTEGER NOT NULL,
                        faculty_id INTEGER NOT NULL,
                        instruction_language TEXT NOT NULL,
                        acknowledged_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        wa_message_id TEXT DEFAULT NULL,
                        UNIQUE(session_id, faculty_id)
                    );
                    CREATE TABLE IF NOT EXISTS faculty_session_interactions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        interaction_token TEXT NOT NULL UNIQUE,
                        faculty_id INTEGER NOT NULL,
                        session_id INTEGER NOT NULL,
                        phone TEXT NOT NULL,
                        originating_wa_message_id TEXT DEFAULT NULL,
                        selected_language TEXT DEFAULT NULL,
                        step TEXT NOT NULL DEFAULT 'awaiting_language',
                        expires_at TEXT NOT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    );
                ");
            }
        } catch (Exception $e) {}
    }

    /**
     * Seeds default English & Malayalam instructions if table is empty.
     */
    public function seedDefaultInstructions(): void {
        $enBody = "PEPP Live Session – Faculty Instructions\n\nPlease join the session on time. Recording and Gemini meeting notes may start automatically when you enter the session, so please do not join earlier than 5 minutes before the scheduled time to avoid unnecessary recording length.\n\nBefore the session, please check your camera, microphone, presentation and overall audio/video quality with the PEPP Admin using the separate test link provided by the Admin. Do not use the live session link for testing.\n\nPlease ensure proper lighting, stable internet connectivity and a suitable environment for conducting the session.\n\nPlease follow the proposed session duration. If you need to cancel or postpone the session, inform the PEPP Admin at least 3 hours before the scheduled start time.\n\nWhen you finish the session, please stop the recording using the Google Meet options (three-dot menu). This is mandatory. If the recording does not stop automatically after you leave, rejoin the session using the same link and stop the recording manually.\n\nThank you for your cooperation and for ensuring a smooth learning experience for our students.";

        $mlBody = "PEPP Live Session – Faculty Instructions\n\nദയവായി നിശ്ചയിച്ച സമയത്ത് തന്നെ session-ൽ join ചെയ്യുക. നിങ്ങൾ session-ൽ പ്രവേശിക്കുമ്പോൾ recording-ഉം Gemini meeting notes-ഉം സ്വയമേവ ആരംഭിക്കാം. അതിനാൽ scheduled time-ന് 5 മിനിറ്റിൽ കൂടുതൽ മുമ്പ് session-ൽ join ചെയ്യരുത്. ഇത് recording അനാവശ്യമായി ദൈർഘ്യമാകുന്നത് ഒഴിവാക്കാൻ സഹായിക്കും.\n\nSession ആരംഭിക്കുന്നതിന് മുമ്പ് camera, microphone, presentation, audio/video quality എന്നിവ PEPP Admin-നൊപ്പം പ്രത്യേകമായി നൽകുന്ന test link ഉപയോഗിച്ച് പരിശോധിക്കുക. Live session link testing-ന് ഉപയോഗിക്കരുത്.\n\nമതിയായ lighting, stable internet connection, അനുയോജ്യമായ teaching environment എന്നിവ ഉറപ്പാക്കുക.\n\nനിർദ്ദേശിച്ചിരിക്കുന്ന session duration പാലിക്കുക. Session cancel ചെയ്യുകയോ postpone ചെയ്യുകയോ ചെയ്യേണ്ട സാഹചര്യമുണ്ടെങ്കിൽ scheduled time-ന് കുറഞ്ഞത് 3 മണിക്കൂർ മുമ്പ് PEPP Admin-നെ അറിയിക്കുക.\n\nSession അവസാനിപ്പിക്കുമ്പോൾ Google Meet-ലെ three-dot menu ഉപയോഗിച്ച് recording stop ചെയ്യുക. ഇത് നിർബന്ധമാണ്. നിങ്ങൾ session-ൽ നിന്ന് പുറത്തുപോയതിന് ശേഷം recording സ്വമേധയാ stop ആയിട്ടില്ലെങ്കിൽ, അതേ link ഉപയോഗിച്ച് വീണ്ടും join ചെയ്ത് recording manually stop ചെയ്യുക.\n\nവിദ്യാർത്ഥികൾക്ക് മികച്ച learning experience നൽകുന്നതിനായി നിങ്ങളുടെ സഹകരണത്തിന് നന്ദി.";

        try {
            $stmt = $this->pdo->prepare("
                INSERT OR IGNORE INTO faculty_session_instructions
                (language_code, language_name, instruction_title, instruction_body, active)
                VALUES
                ('en', 'English', 'PEPP Live Session – Faculty Instructions', ?, 1),
                ('ml', 'Malayalam', 'PEPP Live Session – Faculty Instructions', ?, 1)
            ");
            $stmt->execute([$enBody, $mlBody]);
        } catch (Exception $e) {
            // MySQL fallback syntax
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO faculty_session_instructions
                    (language_code, language_name, instruction_title, instruction_body, active)
                    VALUES
                    ('en', 'English', 'PEPP Live Session – Faculty Instructions', ?, 1),
                    ('ml', 'Malayalam', 'PEPP Live Session – Faculty Instructions', ?, 1)
                    ON DUPLICATE KEY UPDATE active = 1
                ");
                $stmt->execute([$enBody, $mlBody]);
            } catch (Exception $e2) {}
        }
    }
}
