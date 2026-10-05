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
     * account id (e.g. 1, 2), or default if omitted.
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
                    return $acc;
                }
            } catch (Throwable $e) {
                error_log("WhatsAppAccountResolver error: " . $e->getMessage());
            }
        }

        // Virtual fallback for pre-migration installations or known canonical keys
        $cleanKey = strtolower(trim((string)$identifier));
        if ($cleanKey === 'admissions' || $cleanKey === '1') {
            return $this->getLegacyAdmissionsAccount();
        } elseif ($cleanKey === 'notifications' || $cleanKey === '2') {
            return [
                'id' => 2,
                'sender_key' => 'notifications',
                'phone_number_id' => '',
                'display_number' => '917994304400',
                'display_name' => 'PEPP Updates',
                'purpose' => 'Session reminders, faculty session reminders, daily task reminders and university admission notifications',
                'is_default' => 0,
                'status' => 'active'
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
                    return $acc;
                }
            } catch (Throwable $e) {}
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
                    return $acc;
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
                    return $acc;
                }
                // Fallback to admissions key
                $stmt = $this->pdo->prepare("SELECT * FROM whatsapp_accounts WHERE sender_key = 'admissions' LIMIT 1");
                $stmt->execute();
                $acc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acc) {
                    return $acc;
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
                return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}
        }

        // Fallback default list
        $list = [
            $this->getLegacyAdmissionsAccount(),
            [
                'id' => 2,
                'sender_key' => 'notifications',
                'phone_number_id' => '',
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

    private function getLegacyAdmissionsAccount(): array {
        $phoneId = $this->getLegacySetting('whatsapp_phone_id');
        return [
            'id' => 1,
            'sender_key' => 'admissions',
            'phone_number_id' => $phoneId ?: '',
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
}
