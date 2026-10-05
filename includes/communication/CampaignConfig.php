<?php
/**
 * Central campaign / queue pacing configuration.
 *
 * Values are read from admin_settings (setting_name = 'campaign_<key>') and fall back to the
 * safe defaults below. These are ADMIN / INTERNAL controls and are intentionally not exposed
 * in the normal campaign creation UI.
 *
 *   queue_batch_size        Max queue items processed per worker invocation.
 *   enqueue_batch_size      Max campaign recipients moved into the queue per cron cycle (DB-only work).
 *   per_message_delay_ms    Optional sleep between provider calls (0 disables).
 *   max_run_seconds         Soft time budget per worker invocation (stops starting new sends).
 *   retry_delay_seconds     Base delay used for campaign-level retry scheduling.
 *   max_retries             Max automatic attempts for a WhatsApp queue item (informational for reports).
 *   rate_limit_cooldown_s   Global WhatsApp dispatch pause after Meta throttling is detected.
 *   large_campaign_threshold Recipient count that triggers an extra confirmation in the UI.
 *   cron_interval_seconds   Expected cron cadence (used by the processing-time estimate).
 *   avg_send_latency_ms_min / _max  Assumed Meta API latency used by the estimate only.
 */
class CampaignConfig {
    const DEFAULTS = [
        'queue_batch_size'          => 100,
        'enqueue_batch_size'        => 500,
        'per_message_delay_ms'      => 50,
        'max_run_seconds'           => 50,
        'retry_delay_seconds'       => 120,
        'max_retries'               => 3,
        'rate_limit_cooldown_s'     => 120,
        'large_campaign_threshold'  => 500,
        'cron_interval_seconds'     => 60,
        'avg_send_latency_ms_min'   => 250,
        'avg_send_latency_ms_max'   => 700,
    ];

    /** Queue priority for campaign messages (lower than transactional default 0). */
    const CAMPAIGN_QUEUE_PRIORITY = -10;

    const RATE_LIMIT_SETTING = 'whatsapp_rate_limit_until';

    /** @var array<string,int>|null */
    private static $cache = null;

    public static function resetCache(): void {
        self::$cache = null;
    }

    public static function all($pdo): array {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = self::DEFAULTS;
        try {
            $stmt = $pdo->query("SELECT setting_name, setting_value FROM admin_settings WHERE setting_name LIKE 'campaign_%'");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = substr((string)$row['setting_name'], strlen('campaign_'));
                if (array_key_exists($key, self::DEFAULTS) && is_numeric($row['setting_value'])) {
                    $out[$key] = max(0, (int)$row['setting_value']);
                }
            }
        } catch (Throwable $e) {
            // admin_settings unavailable: defaults apply
        }
        // Guard rails: never allow nonsensical values.
        $out['queue_batch_size']   = max(1, min(500, $out['queue_batch_size']));
        $out['enqueue_batch_size'] = max(1, min(2000, $out['enqueue_batch_size']));
        $out['per_message_delay_ms'] = min(5000, $out['per_message_delay_ms']);
        $out['max_run_seconds']    = max(5, min(600, $out['max_run_seconds']));
        $out['cron_interval_seconds'] = max(10, $out['cron_interval_seconds']);
        self::$cache = $out;
        return $out;
    }

    public static function get($pdo, string $key): int {
        $all = self::all($pdo);
        return $all[$key] ?? (self::DEFAULTS[$key] ?? 0);
    }

    /** Test helper: override values in-process. */
    public static function override(array $values): void {
        $base = self::$cache ?? self::DEFAULTS;
        self::$cache = array_merge($base, $values);
    }

    // ── Global rate-limit cooldown ────────────────────────────────────────────

    public static function isRateLimited($pdo): bool {
        return self::rateLimitedUntil($pdo) > time();
    }

    public static function rateLimitedUntil($pdo): int {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ? LIMIT 1");
            $stmt->execute([self::RATE_LIMIT_SETTING]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Records a throttling event; returns the cooldown (seconds) applied. */
    public static function recordRateLimit($pdo): int {
        $cooldown = max(10, self::get($pdo, 'rate_limit_cooldown_s'));
        $until = (string)(time() + $cooldown);
        try {
            $sqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            if ($sqlite) {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO admin_settings (setting_name, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
            } else {
                $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            }
            $stmt->execute([self::RATE_LIMIT_SETTING, $until]);
        } catch (Throwable $e) {
            error_log('CampaignConfig::recordRateLimit failed: ' . $e->getMessage());
        }
        return $cooldown;
    }

    // ── Estimated processing time ─────────────────────────────────────────────

    /**
     * Approximate processing window for $recipients based on the CURRENT queue settings.
     * Never a guarantee: Meta latency, throttling and cron cadence all affect real time.
     *
     * @return array{min_minutes:int,max_minutes:int,cycles:int,per_cycle_min:int,per_cycle_max:int,label:string}
     */
    public static function estimateProcessing($pdo, int $recipients): array {
        $batch   = self::get($pdo, 'queue_batch_size');
        $delay   = self::get($pdo, 'per_message_delay_ms');
        $budget  = self::get($pdo, 'max_run_seconds');
        $cron    = self::get($pdo, 'cron_interval_seconds');
        $latMin  = self::get($pdo, 'avg_send_latency_ms_min');
        $latMax  = self::get($pdo, 'avg_send_latency_ms_max');

        // Messages that fit in one cycle = min(batch, time budget / per-message cost).
        $perCycleFast = max(1, min($batch, (int)floor(($budget * 1000) / max(1, $delay + $latMin))));
        $perCycleSlow = max(1, min($batch, (int)floor(($budget * 1000) / max(1, $delay + $latMax))));

        $cyclesFast = (int)ceil($recipients / $perCycleFast);
        $cyclesSlow = (int)ceil($recipients / $perCycleSlow);

        // One cycle per cron tick; the first tick may begin immediately.
        $minMin = (int)max(1, ceil((max(0, $cyclesFast - 1) * $cron) / 60));
        $maxMin = (int)max($minMin, ceil(($cyclesSlow * $cron) / 60));
        if ($recipients <= 0) {
            $minMin = $maxMin = 0;
        }

        return [
            'min_minutes'   => $minMin,
            'max_minutes'   => $maxMin,
            'cycles'        => $cyclesSlow,
            'per_cycle_min' => $perCycleSlow,
            'per_cycle_max' => $perCycleFast,
            'label'         => $recipients <= 0 ? '' : "approximately {$minMin}\u{2013}{$maxMin} minutes based on current queue settings",
        ];
    }
}
