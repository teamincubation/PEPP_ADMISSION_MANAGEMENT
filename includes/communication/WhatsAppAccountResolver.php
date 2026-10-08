<?php
/**
 * Canonical WhatsApp Accounts & Sender Resolver.
 *
 * Resolves sender_key, account_id, phone_number_id, and event-based sender routing
 * across multiple numbers under the same WhatsApp Business Account.
 * Provides fail-safe backward compatibility with legacy single-number installations.
 */

declare(strict_types=1);

class WhatsAppAccountResolver {
    private static ?self $instance = null;
    private $pdo;
    private ?bool $hasTableCache = null;
    private ?bool $hasEventSenderKeyCol = null;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public static function getInstance($pdo): self {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        }
        return self::$instance;
    }

    /**
     * Checks if whatsapp_accounts table exists in current database schema.
     */
    public function hasWhatsAppAccountsTable(): bool {
        if ($this->hasTableCache !== null) {
            return $this->hasTableCache;
        }

        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_accounts'");
                $this->hasTableCache = (bool)$stmt->fetchColumn();
            } else {
                $stmt = $this->pdo->query("SHOW TABLES LIKE 'whatsapp_accounts'");
                $this->hasTableCache = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $this->hasTableCache = false;
        }

        return $this->hasTableCache;
    }

    /**
     * Alias for hasWhatsAppAccountsTable().
     */
    public function hasAccountsTable(): bool {
        return $this->hasWhatsAppAccountsTable();
    }

    /**
     * Clears internal table/column existence caches.
     */
    public function refresh(): void {
        $this->hasTableCache = null;
        $this->hasEventSenderKeyCol = null;
    }

    /**
     * Checks if a WhatsApp account has a valid, non-empty Meta phone_number_id and is active.
     */
    public function isAccountConfigured(?array $account): bool {
        if (!$account) {
            return false;
        }
        $status = strtolower(trim((string)($account['status'] ?? 'active')));
        if ($status !== 'active') {
            return false;
        }
        $phoneId = trim((string)($account['phone_number_id'] ?? ''));
        return $phoneId !== '';
    }

    /**
     * Resolves a WhatsApp account by sender_key (e.g. 'admissions', 'notifications'),
     * account id (e.g. 1, 2, 3), or default if omitted.
     *
     * @param string|int|null $identifier
     * @return array|null Returns associative account array or null if not found
     */
    public function getAccount($identifier = null): ?array {
        if ($identifier === null || $identifier === '') {
            return $this->getDefaultAccount();
        }

        if ($this->hasWhatsAppAccountsTable()) {
            try {
                if (is_numeric($identifier)) {
                    $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE id = ? LIMIT 1");
                    $stmt->execute([(int)$identifier]);
                } else {
                    $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE sender_key = ? LIMIT 1");
                    $stmt->execute([trim((string)$identifier)]);
                }
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $this->normalizeAccountArray($acc);
                }
            } catch (Throwable $e) {
                error_log("WhatsAppAccountResolver error: " . $e->getMessage());
            }
        }

        // Virtual fallback for pre-migration installations or known canonical keys
        $cleanKey = strtolower(trim((string)$identifier));
        if ($cleanKey === 'admissions' || $cleanKey === '1') {
            return $this->getLegacyAdmissionsAccount();
        } elseif ($cleanKey === 'notifications' || $cleanKey === '3') {
            return [
                'id' => 3,
                'sender_key' => 'notifications',
                'phone_number_id' => '',
                'waba_id' => '1099020233033644',
                'display_number' => '917994304400',
                'display_name' => 'PEPP Updates',
                'purpose' => 'Session reminders, faculty session reminders, daily task reminders and university admission notifications',
                'is_default' => 0,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return null;
    }

    /**
     * Resolves account by Meta phone_number_id.
     */
    public function getAccountByPhoneId(string $phoneId): ?array {
        $cleanPhoneId = trim($phoneId);
        if ($cleanPhoneId === '') {
            return null;
        }

        if ($this->hasWhatsAppAccountsTable()) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE phone_number_id = ? LIMIT 1");
                $stmt->execute([$cleanPhoneId]);
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $this->normalizeAccountArray($acc);
                }
            } catch (Throwable $e) {}
        }

        // Check against PEPP Updates canonical phone ID
        if ($cleanPhoneId === '1293652117171674') {
            return $this->getAccount('notifications');
        }

        // Check against legacy admin_settings
        $legacyPhoneId = $this->getLegacySetting('whatsapp_phone_id');
        if (!empty($legacyPhoneId) && $cleanPhoneId === $legacyPhoneId) {
            return $this->getLegacyAdmissionsAccount();
        }

        return null;
    }

    /**
     * Resolves account by display number (e.g. '916282563209' or '917994304400').
     */
    public function getAccountByDisplayNumber(string $displayNumber): ?array {
        $cleanNumber = preg_replace('/\D/', '', $displayNumber);
        if ($cleanNumber === '') {
            return null;
        }

        if ($this->hasWhatsAppAccountsTable()) {
            try {
                $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE display_number = ? LIMIT 1");
                $stmt->execute([$cleanNumber]);
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $this->normalizeAccountArray($acc);
                }
            } catch (Throwable $e) {}
        }

        if ($cleanNumber === '916282563209' || substr($cleanNumber, -10) === '6282563209') {
            return $this->getLegacyAdmissionsAccount();
        } elseif ($cleanNumber === '917994304400' || substr($cleanNumber, -10) === '7994304400') {
            return $this->getAccount('notifications');
        }

        return null;
    }

    /**
     * Returns the default active WhatsApp account (admissions).
     */
    public function getDefaultAccount(): array {
        if ($this->hasWhatsAppAccountsTable()) {
            try {
                $stmt = $this->pdo->query("SELECT * FROM whatsapp_accounts WHERE is_default = 1 AND status = 'active' LIMIT 1");
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $this->normalizeAccountArray($acc);
                }
                // Fallback to admissions key
                $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE sender_key = 'admissions' LIMIT 1");
                $stmt->execute();
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $this->normalizeAccountArray($acc);
                }
            } catch (Throwable $e) {}
        }

        return $this->getLegacyAdmissionsAccount();
    }

    /**
     * Resolves appropriate sender account for a specific system event name.
     */
    public function resolveAccountForEvent(?string $eventName): array {
        $cleanEvent = strtolower(trim((string)$eventName));
        if ($cleanEvent === '') {
            return $this->getDefaultAccount();
        }

        // 1. Check database communication_event_mappings if available
        if ($this->hasEventSenderKeyColumn()) {
            try {
                $stmt = $this->pdo->prepare("SELECT sender_key FROM communication_event_mappings WHERE event_name = ? LIMIT 1");
                $stmt->execute([$cleanEvent]);
                $sKey = $stmt->fetchColumn();
                if ($sKey) {
                    $acc = $this->getAccount($sKey);
                    if ($acc) {
                        return $acc;
                    }
                }
            } catch (Throwable $e) {}
        }

        // 2. Canonical event routing fallback
        // Notifications events:
        $notificationsEvents = [
            'session_reminder',
            'faculty_session_reminder',
            'daily_task_reminder',
            'university_admission_notification',
            'task_reminder',
            'scheduled_session_reminder'
        ];

        if (in_array($cleanEvent, $notificationsEvents, true)) {
            $notifAcc = $this->getAccount('notifications');
            if ($notifAcc) {
                return $notifAcc;
            }
        }

        // All admissions/transactional events default to admissions:
        return $this->getDefaultAccount();
    }

    /**
     * Returns all registered WhatsApp accounts.
     */
    public function getAllAccounts(bool $onlyActive = true): array {
        if ($this->hasWhatsAppAccountsTable()) {
            try {
                $sql = "SELECT * FROM whatsapp_accounts" . ($onlyActive ? " WHERE status = 'active'" : "") . " ORDER BY is_default DESC, id ASC";
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return array_map([$this, 'normalizeAccountArray'], $rows);
            } catch (Throwable $e) {}
        }

        // Fallback default list
        $list = [
            $this->getLegacyAdmissionsAccount(),
            [
                'id' => 3,
                'sender_key' => 'notifications',
                'phone_number_id' => '',
                'waba_id' => '1099020233033644',
                'display_number' => '917994304400',
                'display_name' => 'PEPP Updates',
                'purpose' => 'Session reminders, faculty session reminders, daily task reminders and university admission notifications',
                'is_default' => 0,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ];

        return $list;
    }

    /**
     * Resolves the authoritative WABA ID for a given account array or identifier.
     */
    public function getWabaId($accountOrIdentifier = null): string {
        $acc = is_array($accountOrIdentifier) ? $accountOrIdentifier : $this->getAccount($accountOrIdentifier);
        if ($acc && !empty($acc['waba_id'])) {
            return trim((string)$acc['waba_id']);
        }
        if ($acc && (($acc['sender_key'] ?? '') === 'notifications' || (int)($acc['id'] ?? 0) === 3)) {
            return '1099020233033644';
        }
        $legacy = $this->getLegacySetting('whatsapp_business_id');
        return !empty($legacy) ? $legacy : '1410328164305566';
    }

    /**
     * Checks if waba_id column exists in whatsapp_accounts table.
     */
    public function hasWabaIdColumn(): bool {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(whatsapp_accounts)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                $hasCol = in_array('waba_id', $names, true);
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM whatsapp_accounts LIKE 'waba_id'");
                $hasCol = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }

    /**
     * Ensures every account array has an authoritative waba_id assigned.
     */
    private function normalizeAccountArray(?array $acc): ?array {
        if (!$acc) {
            return null;
        }
        if (empty($acc['waba_id'])) {
            $senderKey = strtolower(trim((string)($acc['sender_key'] ?? '')));
            $accId = (int)($acc['id'] ?? 0);
            if ($senderKey === 'notifications' || $accId === 3) {
                $acc['waba_id'] = '1099020233033644';
            } else {
                $legacyWaba = $this->getLegacySetting('whatsapp_business_id');
                $acc['waba_id'] = !empty($legacyWaba) ? $legacyWaba : '1410328164305566';
            }
        }
        return $acc;
    }

    private function getLegacyAdmissionsAccount(): array {
        $phoneId = $this->getLegacySetting('whatsapp_phone_id');
        $wabaId  = $this->getLegacySetting('whatsapp_business_id') ?: '1410328164305566';
        return [
            'id' => 1,
            'sender_key' => 'admissions',
            'phone_number_id' => $phoneId ?: '',
            'waba_id' => $wabaId,
            'display_number' => '916282563209',
            'display_name' => 'PEPP Learning',
            'purpose' => 'Admissions, student onboarding, approvals, payment receipts and 2-way inbox',
            'is_default' => 1,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    private function getLegacySetting(string $key): string {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ? LIMIT 1");
            $stmt->execute([$key]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }

    private function hasEventSenderKeyColumn(): bool {
        if ($this->hasEventSenderKeyCol !== null) {
            return $this->hasEventSenderKeyCol;
        }

        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(communication_event_mappings)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                $this->hasEventSenderKeyCol = in_array('sender_key', $names, true);
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM communication_event_mappings LIKE 'sender_key'");
                $this->hasEventSenderKeyCol = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $this->hasEventSenderKeyCol = false;
        }

        return $this->hasEventSenderKeyCol;
    }

    /**
     * Checks if communication_templates table has sender_account_id column.
     */
    public function hasTemplateAccountColumns(): bool {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(communication_templates)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                $hasCol = in_array('sender_account_id', $names, true) && in_array('waba_id', $names, true);
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM communication_templates LIKE 'sender_account_id'");
                $hasCol = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }

    /**
     * Checks if communication_event_mappings table has sender_account_id column.
     */
    public function hasEventMappingAccountColumns(): bool {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(communication_event_mappings)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                $hasCol = in_array('sender_account_id', $names, true);
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM communication_event_mappings LIKE 'sender_account_id'");
                $hasCol = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }

    /**
     * Checks if communication_campaigns table has template_id column.
     */
    public function hasCampaignTemplateIdColumn(): bool {
        static $hasCol = null;
        if ($hasCol !== null) {
            return $hasCol;
        }
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(communication_campaigns)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                $hasCol = in_array('template_id', $names, true);
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM communication_campaigns LIKE 'template_id'");
                $hasCol = (bool)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
            $hasCol = false;
        }
        return $hasCol;
    }

    /**
     * Authoritative Template Resolution by ID with optional sender account enforcement.
     *
     * @param int $templateId
     * @param int|null $expectedSenderAccountId
     * @return array|null Returns template row normalized with sender_account_id & waba_id, or null
     */
    public function getTemplateById(int $templateId, ?int $expectedSenderAccountId = null): ?array {
        if ($templateId <= 0) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM communication_templates WHERE id = ? AND channel = 'whatsapp' LIMIT 1");
            $stmt->execute([$templateId]);
            $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) {
                return null;
            }

            $tpl = $this->normalizeTemplateRow($tpl);

            if ($expectedSenderAccountId !== null && (int)$tpl['sender_account_id'] !== (int)$expectedSenderAccountId) {
                // Reject mismatch server-side
                return null;
            }

            return $tpl;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Authoritative Template Resolution by name, with sender account awareness.
     *
     * @param string $templateName
     * @param int|null $senderAccountId
     * @param string|null $language
     * @return array|null
     */
    public function resolveTemplate(string $templateName, ?int $senderAccountId = null, ?string $language = null): ?array {
        $cleanName = trim($templateName);
        if ($cleanName === '') {
            return null;
        }

        try {
            if ($this->hasTemplateAccountColumns() && $senderAccountId !== null) {
                $sql = "SELECT * FROM communication_templates WHERE channel = 'whatsapp' AND template_name = ? AND sender_account_id = ?";
                $params = [$cleanName, (int)$senderAccountId];
                if (!empty($language)) {
                    $sql .= " AND language = ?";
                    $params[] = $language;
                }
                $sql .= " LIMIT 1";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $this->normalizeTemplateRow($row);
                }
            }

            // Fallback: search by name
            $stmt = $this->pdo->prepare("SELECT * FROM communication_templates WHERE channel = 'whatsapp' AND template_name = ? ORDER BY id DESC");
            $stmt->execute([$cleanName]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($candidates as $cand) {
                $norm = $this->normalizeTemplateRow($cand);
                if ($senderAccountId === null || (int)$norm['sender_account_id'] === (int)$senderAccountId) {
                    if (empty($language) || ($norm['language'] ?? '') === $language) {
                        return $norm;
                    }
                }
            }

            // If no sender-specific match and NO sender account was requested, return first candidate normalized (legacy fallback)
            if ($senderAccountId === null && !empty($candidates)) {
                return $this->normalizeTemplateRow($candidates[0]);
            }
        } catch (Throwable $e) {
            error_log("WhatsAppAccountResolver::resolveTemplate error: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Normalizes a communication_templates row, ensuring sender_account_id and waba_id are populated
     * even if database migration 62 has not yet been applied.
     */
    public function normalizeTemplateRow(array $row): array {
        $meta = json_decode($row['meta_data'] ?? '', true) ?: [];

        $senderAccountId = isset($row['sender_account_id']) && $row['sender_account_id'] !== null ? (int)$row['sender_account_id'] : null;
        $wabaId = !empty($row['waba_id']) ? trim((string)$row['waba_id']) : null;
        $metaTplId = !empty($row['meta_template_id']) ? trim((string)$row['meta_template_id']) : null;

        if ($senderAccountId === null || $wabaId === null) {
            $tplName = $row['template_name'] ?? '';
            $metaWaba = $meta['waba_id'] ?? null;
            $metaAcc = $meta['account_id'] ?? null;
            $metaSender = $meta['sender_key'] ?? null;

            if ($metaWaba === '1099020233033644' || $metaAcc === 3 || $metaSender === 'notifications' ||
                in_array($tplName, ['faculty_session_reminder', 'faculty_session_start', 'faculty_session_start_now', 'faculty_session_cancelled', 'faculty_session_scheduled'], true)) {
                $senderAccountId = 3;
                $wabaId = '1099020233033644';
            } else {
                $senderAccountId = 1;
                $wabaId = '1410328164305566';
            }
        }

        if (empty($metaTplId) && !empty($meta['meta_template_id'])) {
            $metaTplId = (string)$meta['meta_template_id'];
        }

        $row['sender_account_id'] = $senderAccountId;
        $row['waba_id'] = $wabaId;
        $row['meta_template_id'] = $metaTplId;

        return $row;
    }
}

