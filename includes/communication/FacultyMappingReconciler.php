<?php
/**
 * FacultyMappingReconciler.php
 *
 * Reconciles ONLY the five faculty live-session rows in `communication_event_mappings`
 * with the live Meta template definitions (single source of truth:
 * FacultySessionNotificationService::CANONICAL_META_VARIABLE_MAP).
 *
 * Safety properties:
 *  - Touches only the five faculty event names; WHERE clause pins event_name AND template_name.
 *  - Refuses to write if the synced Meta body indexes in communication_templates.meta_data
 *    differ from the canonical map (so ERP metadata, mapping and service can never disagree).
 *  - Backs up the exact original rows (JSON) into a new admin_settings row before updating.
 *  - Never touches template status, Meta, the queue, or any other event mapping.
 */

declare(strict_types=1);

require_once __DIR__ . '/FacultySessionNotificationService.php';

class FacultyMappingReconciler {
    public const BACKUP_PREFIX = 'faculty_event_mapping_backup_';

    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /** Event names (== template names) that this tool is allowed to touch. */
    public static function events(): array {
        return array_keys(FacultySessionNotificationService::CANONICAL_META_VARIABLE_MAP);
    }

    /** Desired parameter_mappings array for an event, keyed by Meta body index. */
    public static function desiredMapping(string $event): array {
        $out = [];
        foreach (FacultySessionNotificationService::CANONICAL_META_VARIABLE_MAP[$event] as $idx => $var) {
            $out[(int)$idx] = ['type' => 'variable', 'value' => $var];
        }
        return $out;
    }

    private function fetchRow(string $event): ?array {
        $st = $this->pdo->prepare("SELECT * FROM communication_event_mappings WHERE event_name = ?");
        $st->execute([$event]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function metaBodyIndexes(string $template): ?array {
        $st = $this->pdo->prepare("SELECT meta_data FROM communication_templates WHERE template_name = ? AND channel = 'whatsapp' LIMIT 2");
        $st->execute([$template]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1 || !$rows[0]) {
            return null; // missing or ambiguous
        }
        $def = CommunicationHelper::getTemplateParameterDefinition($rows[0]);
        return array_map('intval', $def['body']['indexes'] ?? []);
    }

    /**
     * Read-only plan. Each item: event, row_id, template, current, desired, meta_indexes, ok, changed, problem.
     */
    public function plan(): array {
        $plan = [];
        foreach (self::events() as $event) {
            $row = $this->fetchRow($event);
            $desired = self::desiredMapping($event);
            $item = [
                'event' => $event, 'row_id' => $row['id'] ?? null,
                'template' => $row['template_name'] ?? null,
                'current' => $row ? (json_decode((string)$row['parameter_mappings'], true) ?: []) : null,
                'desired' => $desired, 'meta_indexes' => null,
                'ok' => true, 'changed' => false, 'problem' => '',
            ];
            if (!$row) {
                $item['ok'] = false; $item['problem'] = 'mapping row missing';
            } elseif ($row['template_name'] !== $event) {
                $item['ok'] = false; $item['problem'] = "unexpected template_name '{$row['template_name']}'";
            } else {
                $idx = $this->metaBodyIndexes($event);
                $item['meta_indexes'] = $idx;
                if ($idx === null) {
                    $item['ok'] = false; $item['problem'] = 'synced Meta template missing/ambiguous';
                } elseif ($idx !== array_map('intval', array_keys($desired))) {
                    $item['ok'] = false; $item['problem'] = 'synced Meta body indexes [' . implode(',', $idx) . '] differ from canonical map';
                } else {
                    $item['changed'] = ($item['current'] != $desired)
                        || json_encode(self::normalise($item['current'])) !== json_encode(self::normalise($desired));
                }
            }
            $plan[] = $item;
        }
        return $plan;
    }

    private static function normalise(array $m): array {
        ksort($m);
        return $m;
    }

    /**
     * Apply. Returns ['success'=>bool, 'backup_key'=>?string, 'updated'=>string[], 'error'=>?string].
     */
    public function apply(): array {
        $plan = $this->plan();
        foreach ($plan as $p) {
            if (!$p['ok']) {
                return ['success' => false, 'backup_key' => null, 'updated' => [], 'error' => "{$p['event']}: {$p['problem']}"];
            }
        }
        $toChange = array_values(array_filter($plan, fn($p) => $p['changed']));
        if (!$toChange) {
            return ['success' => true, 'backup_key' => null, 'updated' => [], 'error' => null];
        }

        $backupRows = [];
        foreach (self::events() as $event) {
            $backupRows[] = $this->fetchRow($event);
        }
        $backupKey = self::BACKUP_PREFIX . date('YmdHis');

        $this->pdo->beginTransaction();
        try {
            $ins = $this->pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value) VALUES (?, ?)");
            $ins->execute([$backupKey, json_encode($backupRows, JSON_UNESCAPED_UNICODE)]);

            $upd = $this->pdo->prepare(
                "UPDATE communication_event_mappings SET parameter_mappings = ? WHERE event_name = ? AND template_name = ?"
            );
            $updated = [];
            foreach ($toChange as $p) {
                $upd->execute([json_encode($p['desired']), $p['event'], $p['event']]);
                if ($upd->rowCount() !== 1) {
                    throw new RuntimeException("Expected to update exactly 1 row for {$p['event']}, got {$upd->rowCount()}");
                }
                $updated[] = $p['event'];
            }
            $this->pdo->commit();
            return ['success' => true, 'backup_key' => $backupKey, 'updated' => $updated, 'error' => null];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'backup_key' => null, 'updated' => [], 'error' => $e->getMessage()];
        }
    }

    /** Restore the original rows from a backup key (only the five faculty events). */
    public function restore(string $backupKey): array {
        if (strpos($backupKey, self::BACKUP_PREFIX) !== 0) {
            return ['success' => false, 'error' => 'invalid backup key'];
        }
        $st = $this->pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = ?");
        $st->execute([$backupKey]);
        $json = $st->fetchColumn();
        $rows = $json ? json_decode((string)$json, true) : null;
        if (!is_array($rows)) {
            return ['success' => false, 'error' => 'backup not found'];
        }
        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare("UPDATE communication_event_mappings SET template_name = ?, parameter_mappings = ? WHERE id = ? AND event_name = ?");
            foreach ($rows as $r) {
                if (!$r || !in_array($r['event_name'], self::events(), true)) {
                    continue;
                }
                $upd->execute([$r['template_name'], $r['parameter_mappings'], $r['id'], $r['event_name']]);
            }
            $this->pdo->commit();
            return ['success' => true, 'error' => null];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
