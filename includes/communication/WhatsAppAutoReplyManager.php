<?php
/**
 * WhatsAppAutoReplyManager.php
 *
 * Manages the per-phone 10-minute cooldown (600 seconds) for incoming WhatsApp
 * automatic welcome/support replies.
 *
 * Features:
 * - Atomic concurrency-safe reservation using database row-level locking.
 * - Guaranteed independent cooldown per phone number.
 * - High-precision epoch timestamps to eliminate PHP/MySQL timezone discrepancies.
 * - Immediate retry allowance if auto-reply dispatch fails.
 * - Self-healing table initialization for both MySQL (production) and SQLite (testing).
 */

declare(strict_types=1);

require_once __DIR__ . '/CommunicationEngine.php';

class WhatsAppAutoReplyManager {
    public const DEFAULT_COOLDOWN_SECONDS = 600; // 10 minutes

    private PDO $pdo;
    private bool $tableChecked = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Normalizes a phone number to standard E.164 without leading plus.
     */
    public static function normalizePhone(string $phone): string {
        if (class_exists('CommunicationEngine') && method_exists('CommunicationEngine', 'normalizePhone')) {
            return CommunicationEngine::normalizePhone($phone);
        }
        $cleaned = preg_replace('/\D/', '', $phone);
        if (strlen($cleaned) === 11 && strpos($cleaned, '0') === 0) {
            $cleaned = substr($cleaned, 1);
        }
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }
        return $cleaned;
    }

    /**
     * Self-healing table structure initialization.
     */
    public function ensureTableExists(): void {
        if ($this->tableChecked) {
            return;
        }

        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS `whatsapp_auto_reply_cooldown` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `phone_number` TEXT NOT NULL UNIQUE,
                        `last_reply_at` TEXT DEFAULT NULL,
                        `last_reply_ts` INTEGER DEFAULT NULL,
                        `cooldown_until` TEXT DEFAULT NULL,
                        `cooldown_until_ts` INTEGER DEFAULT NULL,
                        `last_reserved_at` TEXT DEFAULT NULL,
                        `last_reserved_ts` INTEGER DEFAULT NULL,
                        `status` TEXT NOT NULL DEFAULT 'idle',
                        `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE UNIQUE INDEX IF NOT EXISTS `uk_warc_phone` ON `whatsapp_auto_reply_cooldown` (`phone_number`);
                    CREATE INDEX IF NOT EXISTS `idx_warc_cooldown_ts` ON `whatsapp_auto_reply_cooldown` (`cooldown_until_ts`);
                ");
            } else {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS `whatsapp_auto_reply_cooldown` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `phone_number` VARCHAR(30) NOT NULL,
                        `last_reply_at` DATETIME DEFAULT NULL,
                        `last_reply_ts` BIGINT DEFAULT NULL,
                        `cooldown_until` DATETIME DEFAULT NULL,
                        `cooldown_until_ts` BIGINT DEFAULT NULL,
                        `last_reserved_at` DATETIME DEFAULT NULL,
                        `last_reserved_ts` BIGINT DEFAULT NULL,
                        `status` VARCHAR(20) NOT NULL DEFAULT 'idle',
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY `uk_phone_number` (`phone_number`),
                        KEY `idx_cooldown_ts` (`cooldown_until_ts`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            }
            $this->tableChecked = true;
        } catch (\Throwable $e) {
            error_log("[AUTO_REPLY_MANAGER_ERROR] ensureTableExists failed: " . $e->getMessage());
        }
    }

    /**
     * Atomically checks whether an auto-reply is permitted for this phone number
     * and reserves the dispatch slot if permitted.
     *
     * @param string $phone
     * @param int $cooldownSeconds
     * @param int|null $currentTime Optional epoch timestamp override for testing
     * @return bool True if reservation granted and auto-reply should be sent; False if suppressed by cooldown
     */
    public function checkAndReserve(string $phone, int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS, ?int $currentTime = null): bool {
        $cleanPhone = self::normalizePhone($phone);
        if (empty($cleanPhone) || strlen($cleanPhone) < 10) {
            return false;
        }

        $now = $currentTime ?? time();
        $this->ensureTableExists();

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }

        try {
            // Guarantee the row exists atomically
            if ($driver === 'sqlite') {
                $stmtIns = $this->pdo->prepare("
                    INSERT OR IGNORE INTO whatsapp_auto_reply_cooldown 
                    (phone_number, status, cooldown_until_ts, last_reserved_ts, last_reply_ts)
                    VALUES (?, 'idle', 0, 0, 0)
                ");
            } else {
                $stmtIns = $this->pdo->prepare("
                    INSERT IGNORE INTO whatsapp_auto_reply_cooldown 
                    (phone_number, status, cooldown_until_ts, last_reserved_ts, last_reply_ts)
                    VALUES (?, 'idle', 0, 0, 0)
                ");
            }
            $stmtIns->execute([$cleanPhone]);

            // Exclusive row lock
            $lockSql = ($driver === 'sqlite')
                ? "SELECT * FROM whatsapp_auto_reply_cooldown WHERE phone_number = ? LIMIT 1"
                : "SELECT * FROM whatsapp_auto_reply_cooldown WHERE phone_number = ? LIMIT 1 FOR UPDATE";
            
            $stmtLock = $this->pdo->prepare($lockSql);
            $stmtLock->execute([$cleanPhone]);
            $row = $stmtLock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                if (!$inTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return false;
            }

            $cooldownUntilTs = (int)($row['cooldown_until_ts'] ?? 0);
            $lastReservedTs = (int)($row['last_reserved_ts'] ?? 0);
            $status = (string)($row['status'] ?? 'idle');

            // Check 1: Is 10-minute cooldown active?
            if ($cooldownUntilTs > $now) {
                if (!$inTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return false;
            }

            // Check 2: Concurrency in-flight lease
            // If status is 'reserved' and reserved within the last 60 seconds, another request is actively sending
            if ($status === 'reserved' && ($now - $lastReservedTs) < 60) {
                if (!$inTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                return false;
            }

            // Reservation granted!
            $dtNow = date('Y-m-d H:i:s', $now);
            $reservedUntil = $now + $cooldownSeconds;
            $dtUntil = date('Y-m-d H:i:s', $reservedUntil);

            $stmtUpd = $this->pdo->prepare("
                UPDATE whatsapp_auto_reply_cooldown
                SET status = 'reserved',
                    last_reserved_at = ?,
                    last_reserved_ts = ?,
                    cooldown_until = ?,
                    cooldown_until_ts = ?,
                    updated_at = ?
                WHERE phone_number = ?
            ");
            $stmtUpd->execute([$dtNow, $now, $dtUntil, $reservedUntil, $dtNow, $cleanPhone]);

            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if (!$inTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("[AUTO_REPLY_MANAGER_ERROR] checkAndReserve failed for {$cleanPhone}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Confirms successful dispatch of the auto-reply and locks the cooldown for cooldownSeconds.
     */
    public function recordSuccess(string $phone, int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS, ?int $currentTime = null): void {
        $cleanPhone = self::normalizePhone($phone);
        if (empty($cleanPhone)) return;

        $now = $currentTime ?? time();
        $until = $now + $cooldownSeconds;
        $dtNow = date('Y-m-d H:i:s', $now);
        $dtUntil = date('Y-m-d H:i:s', $until);

        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE whatsapp_auto_reply_cooldown
                SET status = 'sent',
                    last_reply_at = ?,
                    last_reply_ts = ?,
                    cooldown_until = ?,
                    cooldown_until_ts = ?,
                    updated_at = ?
                WHERE phone_number = ?
            ");
            $stmt->execute([$dtNow, $now, $dtUntil, $until, $dtNow, $cleanPhone]);
        } catch (\Throwable $e) {
            error_log("[AUTO_REPLY_MANAGER_ERROR] recordSuccess failed for {$cleanPhone}: " . $e->getMessage());
        }
    }

    /**
     * Records a failed dispatch attempt, immediately releasing the cooldown reservation so next inbound can retry.
     */
    public function recordFailure(string $phone, ?int $currentTime = null): void {
        $cleanPhone = self::normalizePhone($phone);
        if (empty($cleanPhone)) return;

        $now = $currentTime ?? time();
        $dtNow = date('Y-m-d H:i:s', $now);

        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("
                UPDATE whatsapp_auto_reply_cooldown
                SET status = 'failed',
                    last_reserved_ts = 0,
                    cooldown_until_ts = 0,
                    cooldown_until = NULL,
                    updated_at = ?
                WHERE phone_number = ?
            ");
            $stmt->execute([$dtNow, $cleanPhone]);
        } catch (\Throwable $e) {
            error_log("[AUTO_REPLY_MANAGER_ERROR] recordFailure failed for {$cleanPhone}: " . $e->getMessage());
        }
    }

    /**
     * Retrieves the current cooldown status for a phone number (read-only diagnostic).
     */
    public function getCooldownStatus(string $phone, ?int $currentTime = null): array {
        $cleanPhone = self::normalizePhone($phone);
        if (empty($cleanPhone)) {
            return ['active' => false, 'remaining_seconds' => 0];
        }

        $now = $currentTime ?? time();
        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("
                SELECT phone_number, last_reply_at, last_reply_ts, cooldown_until, cooldown_until_ts, last_reserved_at, last_reserved_ts, status
                FROM whatsapp_auto_reply_cooldown
                WHERE phone_number = ?
                LIMIT 1
            ");
            $stmt->execute([$cleanPhone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ['active' => false, 'remaining_seconds' => 0, 'status' => 'none'];
            }

            $untilTs = (int)($row['cooldown_until_ts'] ?? 0);
            $remaining = max(0, $untilTs - $now);
            $isActive = ($remaining > 0);

            return [
                'active' => $isActive,
                'remaining_seconds' => $remaining,
                'status' => $row['status'] ?? 'idle',
                'last_reply_at' => $row['last_reply_at'] ?? null,
                'last_reply_ts' => (int)($row['last_reply_ts'] ?? 0),
                'cooldown_until' => $row['cooldown_until'] ?? null,
                'cooldown_until_ts' => $untilTs
            ];
        } catch (\Throwable $e) {
            return ['active' => false, 'remaining_seconds' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Clears cooldown for a specific phone number (useful for testing and admin overrides).
     */
    public function resetCooldown(string $phone): void {
        $cleanPhone = self::normalizePhone($phone);
        if (empty($cleanPhone)) return;

        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM whatsapp_auto_reply_cooldown WHERE phone_number = ?");
            $stmt->execute([$cleanPhone]);
        } catch (\Throwable $e) {}
    }
}
