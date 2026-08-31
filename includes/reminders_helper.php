<?php
/**
 * PEPP Learning — Task Reminders & Accountability Module Helper Library
 * Authoritative backend service handling task management, life-cycle auditing,
 * assignment tracking, timer revalidations, and persistent notifications.
 */

if (!function_exists('admins_table_exists')) {
    function admins_table_exists(PDO $pdo): bool {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='admins'")->fetchColumn();
            } else {
                return (bool)$pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn();
            }
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('reminders_table_exists')) {
    function reminders_table_exists(PDO $pdo): bool {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='reminders'")->fetchColumn();
            } else {
                return (bool)$pdo->query("SHOW TABLES LIKE 'reminders'")->fetchColumn();
            }
        } catch (Exception $ex) {
            return false;
        }
    }
}

if (!function_exists('ensure_task_reminders_schema')) {
    function ensure_task_reminders_schema(PDO $pdo, bool $force = false): bool {
        static $task_schema_checked = false;
        if ($task_schema_checked && !$force) {
            return true;
        }

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                // 1. task_reminder_types
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `task_reminder_types` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `name` VARCHAR(100) NOT NULL,
                        `description` TEXT NULL,
                        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                        `created_by_admin_id` INT NULL,
                        `created_by_username` VARCHAR(100) NULL,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY `uniq_task_type_name` (`name`),
                        INDEX `idx_trt_status_name` (`is_active`, `name`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 2. Base reminders table creation / extension
                if (!$pdo->query("SHOW TABLES LIKE 'reminders'")->fetchColumn()) {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS `reminders` (
                            `id` INT AUTO_INCREMENT PRIMARY KEY,
                            `title` VARCHAR(255) NOT NULL,
                            `notes` TEXT NULL,
                            `remind_at` DATETIME NOT NULL,
                            `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
                            `created_by` VARCHAR(100) NULL,
                            `created_by_admin_id` INT NULL,
                            `created_by_username` VARCHAR(100) NULL,
                            `assigned_to` VARCHAR(100) NULL,
                            `assigned_to_admin_id` INT NULL,
                            `assigned_to_username` VARCHAR(100) NULL,
                            `assigned_by_admin_id` INT NULL,
                            `assigned_by_username` VARCHAR(100) NULL,
                            `assigned_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                            `task_type_id` INT NULL,
                            `completed_at` DATETIME NULL,
                            `completed_by` VARCHAR(100) NULL,
                            `completed_by_admin_id` INT NULL,
                            `completed_by_username` VARCHAR(100) NULL,
                            `latest_remarks` TEXT NULL,
                            `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
                            `last_status_updated_at` DATETIME NULL,
                            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                            INDEX `idx_rem_type` (`task_type_id`),
                            INDEX `idx_rem_assignee_status` (`assigned_to_admin_id`, `status`),
                            INDEX `idx_rem_creator` (`created_by_admin_id`, `status`),
                            INDEX `idx_rem_due_time` (`remind_at`, `status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");
                } else {
                    $requiredCols = [
                        'task_type_id' => "INT NULL",
                        'created_by_admin_id' => "INT NULL",
                        'created_by_username' => "VARCHAR(100) NULL",
                        'assigned_by_admin_id' => "INT NULL",
                        'assigned_by_username' => "VARCHAR(100) NULL",
                        'assigned_to_admin_id' => "INT NULL",
                        'assigned_to_username' => "VARCHAR(100) NULL",
                        'assigned_at' => "DATETIME NULL",
                        'completed_by_admin_id' => "INT NULL",
                        'completed_by_username' => "VARCHAR(100) NULL",
                        'completed_at' => "DATETIME NULL",
                        'latest_remarks' => "TEXT NULL",
                        'email_sent' => "TINYINT(1) NOT NULL DEFAULT 0",
                        'last_status_updated_at' => "DATETIME NULL"
                    ];
                    foreach ($requiredCols as $colName => $colDef) {
                        try {
                            $chk = $pdo->query("SHOW COLUMNS FROM `reminders` LIKE '{$colName}'")->fetch();
                            if (!$chk) {
                                $pdo->exec("ALTER TABLE `reminders` ADD COLUMN `{$colName}` {$colDef}");
                            }
                        } catch (Throwable $eCol) {}
                    }

                    try {
                        $statusCol = $pdo->query("SHOW COLUMNS FROM `reminders` LIKE 'status'")->fetch();
                        if ($statusCol && strpos(strtolower((string)$statusCol['Type']), 'enum') !== false) {
                            $pdo->exec("ALTER TABLE `reminders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending'");
                        }
                    } catch (Throwable $eStat) {}

                    $indexesToCreate = [
                        'idx_rem_type' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_type` (`task_type_id`)',
                        'idx_rem_assignee_status' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_assignee_status` (`assigned_to_admin_id`, `status`)',
                        'idx_rem_creator' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_creator` (`created_by_admin_id`, `status`)',
                        'idx_rem_due_time' => 'ALTER TABLE `reminders` ADD INDEX `idx_rem_due_time` (`remind_at`, `status`)'
                    ];
                    foreach ($indexesToCreate as $idxName => $alterSql) {
                        try {
                            $idxChk = $pdo->query("SHOW INDEX FROM `reminders` WHERE Key_name = '{$idxName}'")->fetch();
                            if (!$idxChk) {
                                $pdo->exec($alterSql);
                            }
                        } catch (Throwable $eIdx) {}
                    }
                }

                // 3. task_reminder_assignments
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `task_id` INT NOT NULL,
                        `assigned_by_admin_id` INT NULL,
                        `assigned_by_username` VARCHAR(100) NULL,
                        `assigned_to_admin_id` INT NULL,
                        `assigned_to_username` VARCHAR(100) NULL,
                        `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `ended_at` DATETIME NULL,
                        `is_current` TINYINT(1) NOT NULL DEFAULT 1,
                        INDEX `idx_tra_task` (`task_id`, `is_current`),
                        INDEX `idx_tra_assignee` (`assigned_to_admin_id`, `is_current`),
                        INDEX `idx_tra_assigned_by` (`assigned_by_admin_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 4. task_reminder_status_history
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `task_id` INT NOT NULL,
                        `event_type` VARCHAR(50) NOT NULL,
                        `old_status` VARCHAR(50) NULL,
                        `new_status` VARCHAR(50) NULL,
                        `changed_by_admin_id` INT NULL,
                        `changed_by_username` VARCHAR(100) NULL,
                        `remarks` TEXT NULL,
                        `details_json` TEXT NULL,
                        `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_trsh_task_time` (`task_id`, `changed_at`),
                        INDEX `idx_trsh_event` (`event_type`),
                        INDEX `idx_trsh_changed_by` (`changed_by_admin_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 5. task_reminder_notifications
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `task_id` INT NOT NULL,
                        `recipient_admin_id` INT NULL,
                        `recipient_username` VARCHAR(64) NOT NULL,
                        `sender_admin_id` INT NULL,
                        `sender_username` VARCHAR(64) NULL,
                        `notification_type` VARCHAR(32) NOT NULL,
                        `event_key` VARCHAR(64) NOT NULL,
                        `message` TEXT NULL,
                        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                        `is_dismissed` TINYINT(1) NOT NULL DEFAULT 0,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `read_at` DATETIME NULL,
                        UNIQUE KEY `uniq_task_event_notif` (`task_id`, `recipient_username`, `notification_type`, `event_key`),
                        INDEX `idx_trn_recipient_unread` (`recipient_username`, `is_read`, `created_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 6. Seed initial task types
                $cntTypes = (int)$pdo->query("SELECT COUNT(*) FROM `task_reminder_types`")->fetchColumn();
                if ($cntTypes === 0) {
                    $pdo->exec("
                        INSERT IGNORE INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`, `created_at`) VALUES
                        ('Daily Task Reminder', 'Routine daily reminders and operational duties', 1, 'System', NOW()),
                        ('Mentoring', 'Student academic mentoring, progress review and counseling sessions', 1, 'System', NOW()),
                        ('Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests', 1, 'System', NOW()),
                        ('Student Follow-up', 'Calling students regarding admission, attendance, and general queries', 1, 'System', NOW()),
                        ('Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review', 1, 'System', NOW()),
                        ('Academic Task', 'Curriculum planning, question paper design, and study material uploads', 1, 'System', NOW()),
                        ('Administrative Task', 'Office paperwork, certificate generation, and staff coordination', 1, 'System', NOW()),
                        ('Meeting', 'Internal staff, academic committee, and management meetings', 1, 'System', NOW()),
                        ('Documentation', 'Student records, onboarding verifications, and compliance filing', 1, 'System', NOW()),
                        ('General Task', 'General administrative task and miscellaneous reminders', 1, 'System', NOW()),
                        ('Other', 'Custom tasks not covered in standard categories', 1, 'System', NOW());
                    ");
                }

                // 7. In-place backfill for legacy reminders
                $generalTypeId = (int)$pdo->query("SELECT `id` FROM `task_reminder_types` WHERE `name` = 'General Task' LIMIT 1")->fetchColumn();
                if ($generalTypeId > 0) {
                    $pdo->exec("UPDATE `reminders` SET `task_type_id` = {$generalTypeId} WHERE `task_type_id` IS NULL");
                }
                $pdo->exec("UPDATE `reminders` SET `created_by_username` = `created_by` WHERE `created_by_username` IS NULL AND `created_by` IS NOT NULL");
                $pdo->exec("UPDATE `reminders` SET `assigned_to_username` = `assigned_to` WHERE `assigned_to_username` IS NULL AND `assigned_to` IS NOT NULL");
                $pdo->exec("UPDATE `reminders` SET `assigned_by_username` = `created_by` WHERE `assigned_by_username` IS NULL AND `created_by` IS NOT NULL");
                $pdo->exec("UPDATE `reminders` SET `completed_by_username` = `completed_by` WHERE `completed_by_username` IS NULL AND `completed_by` IS NOT NULL");

                try {
                    if ($pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn()) {
                        $admins = $pdo->query("SELECT id, username FROM admins")->fetchAll(PDO::FETCH_ASSOC);
                        $stmtUpdCreator = $pdo->prepare("UPDATE reminders SET created_by_admin_id = ? WHERE created_by_admin_id IS NULL AND created_by_username = ?");
                        $stmtUpdAssignee = $pdo->prepare("UPDATE reminders SET assigned_to_admin_id = ? WHERE assigned_to_admin_id IS NULL AND assigned_to_username = ?");
                        $stmtUpdAssigner = $pdo->prepare("UPDATE reminders SET assigned_by_admin_id = ? WHERE assigned_by_admin_id IS NULL AND assigned_by_username = ?");
                        $stmtUpdCompleter = $pdo->prepare("UPDATE reminders SET completed_by_admin_id = ? WHERE completed_by_admin_id IS NULL AND completed_by_username = ?");
                        foreach ($admins as $adm) {
                            $stmtUpdCreator->execute([$adm['id'], $adm['username']]);
                            $stmtUpdAssignee->execute([$adm['id'], $adm['username']]);
                            $stmtUpdAssigner->execute([$adm['id'], $adm['username']]);
                            $stmtUpdCompleter->execute([$adm['id'], $adm['username']]);
                        }
                    }
                } catch (Throwable $eAdm) {}

                try {
                    $pdo->exec("
                        INSERT IGNORE INTO `task_reminder_assignments` (`task_id`, `assigned_by_admin_id`, `assigned_by_username`, `assigned_to_admin_id`, `assigned_to_username`, `assigned_at`, `is_current`)
                        SELECT r.`id`, r.`created_by_admin_id`, r.`created_by_username`, r.`assigned_to_admin_id`, r.`assigned_to_username`, COALESCE(r.`created_at`, NOW()), 1
                        FROM `reminders` r
                        WHERE NOT EXISTS (SELECT 1 FROM `task_reminder_assignments` tra WHERE tra.`task_id` = r.`id`);
                    ");
                    $pdo->exec("
                        INSERT IGNORE INTO `task_reminder_status_history` (`task_id`, `event_type`, `old_status`, `new_status`, `changed_by_admin_id`, `changed_by_username`, `remarks`, `changed_at`)
                        SELECT r.`id`, 'CREATED', NULL, r.`status`, r.`created_by_admin_id`, 'SYSTEM MIGRATION', 'Initial migration from legacy reminders', COALESCE(r.`created_at`, NOW())
                        FROM `reminders` r
                        WHERE NOT EXISTS (SELECT 1 FROM `task_reminder_status_history` trsh WHERE trsh.`task_id` = r.`id`);
                    ");
                } catch (Throwable $eHist) {}
            } else {
                // SQLite compatibility for tests
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `reminders` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `title` VARCHAR(255) NOT NULL,
                        `notes` TEXT NULL,
                        `remind_at` DATETIME NOT NULL,
                        `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
                        `created_by` VARCHAR(100) NULL,
                        `created_by_admin_id` INTEGER NULL,
                        `created_by_username` VARCHAR(100) NULL,
                        `assigned_to` VARCHAR(100) NULL,
                        `assigned_to_admin_id` INTEGER NULL,
                        `assigned_to_username` VARCHAR(100) NULL,
                        `assigned_by_admin_id` INTEGER NULL,
                        `assigned_by_username` VARCHAR(100) NULL,
                        `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `task_type_id` INTEGER NULL,
                        `completed_at` DATETIME NULL,
                        `completed_by` VARCHAR(100) NULL,
                        `completed_by_admin_id` INTEGER NULL,
                        `completed_by_username` VARCHAR(100) NULL,
                        `latest_remarks` TEXT NULL,
                        `email_sent` INTEGER NOT NULL DEFAULT 0,
                        `last_status_updated_at` DATETIME NULL,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NULL
                    );
                    CREATE TABLE IF NOT EXISTS `task_reminder_types` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `name` VARCHAR(100) NOT NULL UNIQUE,
                        `description` TEXT NULL,
                        `is_active` INTEGER NOT NULL DEFAULT 1,
                        `created_by_admin_id` INTEGER NULL,
                        `created_by_username` VARCHAR(100) NULL,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NULL
                    );
                    CREATE TABLE IF NOT EXISTS `task_reminder_assignments` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `task_id` INTEGER NOT NULL,
                        `assigned_by_admin_id` INTEGER NULL,
                        `assigned_by_username` VARCHAR(100) NULL,
                        `assigned_to_admin_id` INTEGER NULL,
                        `assigned_to_username` VARCHAR(100) NULL,
                        `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `ended_at` DATETIME NULL,
                        `is_current` INTEGER NOT NULL DEFAULT 1
                    );
                    CREATE TABLE IF NOT EXISTS `task_reminder_status_history` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `task_id` INTEGER NOT NULL,
                        `event_type` VARCHAR(50) NOT NULL,
                        `old_status` VARCHAR(50) NULL,
                        `new_status` VARCHAR(50) NULL,
                        `changed_by_admin_id` INTEGER NULL,
                        `changed_by_username` VARCHAR(100) NULL,
                        `remarks` TEXT NULL,
                        `details_json` TEXT NULL,
                        `changed_at` DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                    CREATE TABLE IF NOT EXISTS `task_reminder_notifications` (
                        `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                        `task_id` INTEGER NOT NULL,
                        `recipient_admin_id` INTEGER NULL,
                        `recipient_username` VARCHAR(64) NOT NULL,
                        `sender_admin_id` INTEGER NULL,
                        `sender_username` VARCHAR(64) NULL,
                        `notification_type` VARCHAR(32) NOT NULL,
                        `event_key` VARCHAR(64) NOT NULL,
                        `message` TEXT NULL,
                        `is_read` INTEGER NOT NULL DEFAULT 0,
                        `is_dismissed` INTEGER NOT NULL DEFAULT 0,
                        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `read_at` DATETIME NULL
                    );
                ");

                // Seed SQLite default types
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `task_reminder_types`")->fetchColumn();
                if ($cnt === 0) {
                    $defaultTypes = [
                        ['Daily Task Reminder', 'Routine daily reminders and operational duties'],
                        ['Mentoring', 'Student academic mentoring, progress review and counseling sessions'],
                        ['Session Scheduling', 'Scheduling online batches, faculty lectures, and mega tests'],
                        ['Student Follow-up', 'Calling students regarding admission, attendance, and general queries'],
                        ['Payment Follow-up', 'Fee installment recovery, payment verification, and voucher review'],
                        ['Academic Task', 'Curriculum planning, question paper design, and study material uploads'],
                        ['Administrative Task', 'Office paperwork, certificate generation, and staff coordination'],
                        ['Meeting', 'Internal staff, academic committee, and management meetings'],
                        ['Documentation', 'Student records, onboarding verifications, and compliance filing'],
                        ['General Task', 'General administrative task and miscellaneous reminders'],
                        ['Other', 'Custom tasks not covered in standard categories']
                    ];
                    $stmtIns = $pdo->prepare("INSERT OR IGNORE INTO `task_reminder_types` (`name`, `description`, `is_active`, `created_by_username`, `created_at`) VALUES (?, ?, 1, 'System', datetime('now'))");
                    foreach ($defaultTypes as $dt) {
                        $stmtIns->execute([$dt[0], $dt[1]]);
                    }
                }
            }

            $task_schema_checked = true;
            return true;
        } catch (Throwable $e) {
            error_log("ensure_task_reminders_schema error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('task_reminders_ensure_schema')) {
    function task_reminders_ensure_schema(PDO $pdo): void {
        ensure_task_reminders_schema($pdo);
    }
}

/**
 * Resolve admin identity (both ID and Username).
 */
function task_reminder_get_admin_identity(PDO $pdo, ?string $username = null, ?int $admin_id = null): array {
    $resolved = [
        'id' => $admin_id ?? 0,
        'username' => $username ?? '',
        'full_name' => $username ?? 'System Admin',
        'role' => 'admin',
        'status' => 'active'
    ];

    if (!admins_table_exists($pdo)) {
        return $resolved;
    }

    try {
        if ($admin_id && $admin_id > 0) {
            $stmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM admins WHERE id = ? LIMIT 1");
            $stmt->execute([$admin_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'full_name' => $row['full_name'] ?: $row['username'],
                    'role' => $row['role'] ?? 'admin',
                    'status' => $row['status'] ?? 'active'
                ];
            }
        } elseif (!empty($username)) {
            $stmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM admins WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'username' => $row['username'],
                    'full_name' => $row['full_name'] ?: $row['username'],
                    'role' => $row['role'] ?? 'admin',
                    'status' => $row['status'] ?? 'active'
                ];
            }
        }
    } catch (Exception $e) {
        error_log("task_reminder_get_admin_identity error: " . $e->getMessage());
    }

    return $resolved;
}

/**
 * Task Types Management Functions
 */
function task_types_get_all(PDO $pdo, bool $only_active = true): array {
    task_reminders_ensure_schema($pdo);
    try {
        $sql = "SELECT id, name, description, is_active, created_by_username, created_at FROM task_reminder_types";
        if ($only_active) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name ASC";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        error_log("task_types_get_all error: " . $e->getMessage());
        return [];
    }
}

function task_types_get_by_id(PDO $pdo, int $id): ?array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM task_reminder_types WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("task_types_get_by_id error: " . $e->getMessage());
        return null;
    }
}

function task_types_save(PDO $pdo, array $data, ?int $admin_id, string $username): array {
    task_reminders_ensure_schema($pdo);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $name = trim($data['name'] ?? '');
    $description = trim($data['description'] ?? '');
    $is_active = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

    if ($name === '') {
        return ['success' => false, 'message' => 'Task Type name is required.'];
    }

    // Normalized uniqueness check: LOWER(TRIM(name))
    try {
        $chkSql = "SELECT id, name FROM task_reminder_types WHERE LOWER(TRIM(name)) = LOWER(?)";
        if ($id > 0) {
            $chkSql .= " AND id != " . (int)$id;
        }
        $chkSql .= " LIMIT 1";
        $stmtChk = $pdo->prepare($chkSql);
        $stmtChk->execute([$name]);
        if ($stmtChk->fetch()) {
            return ['success' => false, 'message' => 'A Task Type with this name already exists.'];
        }

        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE task_reminder_types SET name = ?, description = ?, is_active = ?, updated_at = ? WHERE id = ?");
            $stmt->execute([$name, $description, $is_active, $now, $id]);
            return ['success' => true, 'id' => $id, 'message' => 'Task Type updated successfully.'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO task_reminder_types (name, description, is_active, created_by_admin_id, created_by_username, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $is_active, $admin_id, $username, $now]);
            $newId = (int)$pdo->lastInsertId();
            return ['success' => true, 'id' => $newId, 'message' => 'Task Type created successfully.'];
        }
    } catch (Exception $e) {
        error_log("task_types_save error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

function task_types_toggle_active(PDO $pdo, int $id, bool $is_active): bool {
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE task_reminder_types SET is_active = ?, updated_at = ? WHERE id = ?");
        return $stmt->execute([(int)$is_active, $now, $id]);
    } catch (Exception $e) {
        error_log("task_types_toggle_active error: " . $e->getMessage());
        return false;
    }
}

function task_types_usage_count(PDO $pdo, int $id): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reminders WHERE task_type_id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Task Reminders — Lightweight Header Summary
 * Returns counts and due task IDs to keep response payload minimal (<2ms).
 */
function task_reminders_get_summary(PDO $pdo, int $admin_id, string $admin_username): array {
    task_reminders_ensure_schema($pdo);
    $res = [
        'pending_count' => 0,
        'overdue_count' => 0,
        'in_progress_count' => 0,
        'due_count' => 0,
        'assigned_by_me_pending' => 0,
        'new_notifications' => 0,
        'due_task_ids' => [],
        'server_time' => date('Y-m-d H:i:s')
    ];

    if (!reminders_table_exists($pdo)) {
        return $res;
    }

    try {
        // Query assigned active tasks for this admin
        $sql = "SELECT id, remind_at, status FROM reminders
                WHERE status IN ('pending', 'in_progress')
                AND (assigned_to_admin_id = ? OR assigned_to_username = ? OR assigned_to = ? OR assigned_to = '__ALL__')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id, $admin_username, $admin_username]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($tasks as $t) {
            $remindTime = strtotime($t['remind_at']);
            $isOverdue = ($remindTime < $now);

            if ($t['status'] === 'in_progress') {
                $res['in_progress_count']++;
            } else {
                $res['pending_count']++;
            }

            if ($isOverdue) {
                $res['overdue_count']++;
            }

            // Is it due right now or past due?
            if ($remindTime <= $now) {
                $res['due_count']++;
                $res['due_task_ids'][] = (int)$t['id'];
            }
        }

        // Assigned by me pending tasks count
        $stmtAssigned = $pdo->prepare("SELECT COUNT(*) FROM reminders
            WHERE status IN ('pending', 'in_progress')
            AND (created_by_admin_id = ? OR created_by_username = ? OR created_by = ?)
            AND (assigned_to_admin_id != ? AND assigned_to_username != ? AND assigned_to != ?)");
        $stmtAssigned->execute([$admin_id, $admin_username, $admin_username, $admin_id, $admin_username, $admin_username]);
        $res['assigned_by_me_pending'] = (int)$stmtAssigned->fetchColumn();

        // Unread notifications count
        try {
            $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM task_reminder_notifications
                WHERE (recipient_admin_id = ? OR recipient_username = ?) AND is_read = 0");
            $stmtNotif->execute([$admin_id, $admin_username]);
            $res['new_notifications'] = (int)$stmtNotif->fetchColumn();
        } catch (Exception $eN) {}

    } catch (Exception $e) {
        error_log("task_reminders_get_summary error: " . $e->getMessage());
    }

    return $res;
}

/**
 * Authoritative Server Due Verification
 * Checks if a specific task is genuinely due, pending/in_progress, and authorized for the current admin.
 */
function task_reminders_verify_due_alert(PDO $pdo, int $task_id, int $admin_id, string $admin_username): ?array {
    task_reminders_ensure_schema($pdo);
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return null;
    }

    try {
        $nowDate = date('Y-m-d H:i:s');
        $sql = "SELECT r.*, tt.name as task_type_name
                FROM reminders r
                LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
                WHERE r.id = ?
                AND r.status IN ('pending', 'in_progress')
                AND r.remind_at <= ?
                AND (r.assigned_to_admin_id = ? OR r.assigned_to_username = ? OR r.assigned_to = ? OR r.assigned_to = '__ALL__')
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$task_id, $nowDate, $admin_id, $admin_username, $admin_username]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return null;
        }

        // Format task for popup display
        $task['is_overdue'] = (strtotime($task['remind_at']) < time());
        $task['display_status'] = $task['is_overdue'] ? 'overdue' : $task['status'];
        $task['formatted_due'] = date('d M Y, h:i A', strtotime($task['remind_at']));
        $task['task_type_name'] = $task['task_type_name'] ?: 'General Task';

        return $task;
    } catch (Exception $e) {
        error_log("task_reminders_verify_due_alert error: " . $e->getMessage());
        return null;
    }
}

/**
 * List "My Tasks" for current logged-in admin with derived overdue state.
 */
function task_reminders_list_my_tasks(PDO $pdo, int $admin_id, string $admin_username, array $filters = []): array {
    task_reminders_ensure_schema($pdo);
    if (!reminders_table_exists($pdo)) {
        return [];
    }

    try {
        $nowDate = date('Y-m-d H:i:s');
        $where = ["(r.assigned_to_admin_id = ? OR r.assigned_to_username = ? OR r.assigned_to = ? OR r.assigned_to = '__ALL__')"];
        $params = [$admin_id, $admin_username, $admin_username];

        // Filter by status tab if given
        $status_filter = strtolower(trim($filters['status'] ?? ''));
        if ($status_filter === 'pending') {
            $where[] = "r.status = 'pending'";
        } elseif ($status_filter === 'upcoming') {
            $where[] = "r.status = 'pending' AND r.remind_at >= '{$nowDate}'";
        } elseif ($status_filter === 'in_progress') {
            $where[] = "r.status = 'in_progress'";
        } elseif ($status_filter === 'overdue') {
            $where[] = "r.status IN ('pending', 'in_progress') AND r.remind_at < '{$nowDate}'";
        } elseif ($status_filter === 'completed') {
            $where[] = "r.status = 'completed'";
        } elseif ($status_filter === 'cancelled') {
            $where[] = "r.status = 'cancelled'";
        } elseif ($status_filter === 'active') {
            $where[] = "r.status IN ('pending', 'in_progress')";
        }

        if (!empty($filters['task_type_id'])) {
            $where[] = "r.task_type_id = ?";
            $params[] = (int)$filters['task_type_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(r.title LIKE ? OR r.notes LIKE ?)";
            $params[] = '%' . trim($filters['search']) . '%';
            $params[] = '%' . trim($filters['search']) . '%';
        }

        $sql = "SELECT r.*, tt.name as task_type_name
                FROM reminders r
                LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
                WHERE " . implode(" AND ", $where) . "
                ORDER BY
                    CASE
                        WHEN r.status IN ('pending', 'in_progress') AND r.remind_at < '{$nowDate}' THEN 1
                        WHEN r.status = 'in_progress' THEN 2
                        WHEN r.status = 'pending' THEN 3
                        ELSE 4
                    END,
                    r.remind_at ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as &$r) {
            $r['task_type_name'] = $r['task_type_name'] ?: 'General Task';
            $remindTimestamp = strtotime($r['remind_at']);
            $isOverdue = ($r['status'] === 'pending' || $r['status'] === 'in_progress') && ($remindTimestamp < $now);
            $r['is_overdue'] = $isOverdue;
            $r['display_status'] = ($r['status'] === 'completed' || $r['status'] === 'cancelled') ? $r['status'] : ($isOverdue ? 'overdue' : $r['status']);
            $r['formatted_due'] = date('d M Y, h:i A', $remindTimestamp);
            $r['formatted_created'] = date('d M Y, h:i A', strtotime($r['created_at']));
            $r['formatted_completed'] = !empty($r['completed_at']) ? date('d M Y, h:i A', strtotime($r['completed_at'])) : null;
        }

        return $rows;
    } catch (Exception $e) {
        error_log("task_reminders_list_my_tasks error: " . $e->getMessage());
        return [];
    }
}

/**
 * List "Assigned by Me" Tasks (Admin A monitoring tasks assigned to Admin B).
 * Super Admins can optionally view all assigned tasks.
 */
function task_reminders_list_assigned_by_me(PDO $pdo, int $admin_id, string $admin_username, array $filters = [], bool $is_super_admin = false): array {
    task_reminders_ensure_schema($pdo);
    if (!reminders_table_exists($pdo)) {
        return [];
    }

    try {
        $where = [];
        $params = [];

        if (!$is_super_admin || empty($filters['all_assigned'])) {
            $where[] = "(r.created_by_admin_id = ? OR r.created_by_username = ? OR r.created_by = ?)";
            $params[] = $admin_id;
            $params[] = $admin_username;
            $params[] = $admin_username;
        }

        $nowDate = date('Y-m-d H:i:s');
        $status_filter = strtolower(trim($filters['status'] ?? ''));
        if ($status_filter === 'pending') {
            $where[] = "r.status = 'pending'";
        } elseif ($status_filter === 'upcoming') {
            $where[] = "r.status = 'pending' AND r.remind_at >= '{$nowDate}'";
        } elseif ($status_filter === 'in_progress') {
            $where[] = "r.status = 'in_progress'";
        } elseif ($status_filter === 'overdue') {
            $where[] = "r.status IN ('pending', 'in_progress') AND r.remind_at < '{$nowDate}'";
        } elseif ($status_filter === 'completed') {
            $where[] = "r.status = 'completed'";
        } elseif ($status_filter === 'cancelled') {
            $where[] = "r.status = 'cancelled'";
        } elseif ($status_filter === 'active') {
            $where[] = "r.status IN ('pending', 'in_progress')";
        }

        if (!empty($filters['assigned_to_username'])) {
            $where[] = "(r.assigned_to_username = ? OR r.assigned_to = ?)";
            $params[] = $filters['assigned_to_username'];
            $params[] = $filters['assigned_to_username'];
        }

        if (!empty($filters['task_type_id'])) {
            $where[] = "r.task_type_id = ?";
            $params[] = (int)$filters['task_type_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(r.title LIKE ? OR r.notes LIKE ?)";
            $params[] = '%' . trim($filters['search']) . '%';
            $params[] = '%' . trim($filters['search']) . '%';
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT r.*, tt.name as task_type_name
                FROM reminders r
                LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
                {$whereClause}
                ORDER BY r.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($rows as &$r) {
            $r['task_type_name'] = $r['task_type_name'] ?: 'General Task';
            $remindTimestamp = strtotime($r['remind_at']);
            $isOverdue = ($r['status'] === 'pending' || $r['status'] === 'in_progress') && ($remindTimestamp < $now);
            $r['is_overdue'] = $isOverdue;
            $r['display_status'] = ($r['status'] === 'completed' || $r['status'] === 'cancelled') ? $r['status'] : ($isOverdue ? 'overdue' : $r['status']);
            $r['formatted_due'] = date('d M Y, h:i A', $remindTimestamp);
            $r['formatted_created'] = date('d M Y, h:i A', strtotime($r['created_at']));
            $r['formatted_completed'] = !empty($r['completed_at']) ? date('d M Y, h:i A', strtotime($r['completed_at'])) : null;
        }

        return $rows;
    } catch (Exception $e) {
        error_log("task_reminders_list_assigned_by_me error: " . $e->getMessage());
        return [];
    }
}

/**
 * Task Details & Complete Timeline (with Authorization & IDOR check).
 */
function task_reminders_get_details(PDO $pdo, int $task_id, int $admin_id, string $admin_username, bool $is_super_admin = false): ?array {
    task_reminders_ensure_schema($pdo);
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT r.*, tt.name as task_type_name
            FROM reminders r
            LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
            WHERE r.id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return null;
        }

        // Authorization check: Is current user Creator, Assignee, or Super Admin?
        $isCreator = ($task['created_by_admin_id'] == $admin_id || $task['created_by_username'] === $admin_username || $task['created_by'] === $admin_username);
        $isAssignee = ($task['assigned_to_admin_id'] == $admin_id || $task['assigned_to_username'] === $admin_username || $task['assigned_to'] === $admin_username || $task['assigned_to'] === '__ALL__');

        if (!$is_super_admin && !$isCreator && !$isAssignee) {
            return null; // IDOR protected
        }

        $now = time();
        $remindTimestamp = strtotime($task['remind_at']);
        $isOverdue = ($task['status'] === 'pending' || $task['status'] === 'in_progress') && ($remindTimestamp < $now);
        $task['is_overdue'] = $isOverdue;
        $task['display_status'] = ($task['status'] === 'completed' || $task['status'] === 'cancelled') ? $task['status'] : ($isOverdue ? 'overdue' : $task['status']);
        $task['formatted_due'] = date('d M Y, h:i A', $remindTimestamp);
        $task['formatted_created'] = date('d M Y, h:i A', strtotime($task['created_at']));
        $task['formatted_completed'] = !empty($task['completed_at']) ? date('d M Y, h:i A', strtotime($task['completed_at'])) : null;
        $task['task_type_name'] = $task['task_type_name'] ?: 'General Task';

        // Load immutable assignment history
        $stmtAss = $pdo->prepare("SELECT * FROM task_reminder_assignments WHERE task_id = ? ORDER BY assigned_at ASC");
        $stmtAss->execute([$task_id]);
        $assignments = $stmtAss->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($assignments as &$a) {
            $a['formatted_assigned_at'] = date('d M Y, h:i A', strtotime($a['assigned_at']));
            $a['formatted_ended_at'] = !empty($a['ended_at']) ? date('d M Y, h:i A', strtotime($a['ended_at'])) : null;
        }

        // Load immutable status history
        $stmtHist = $pdo->prepare("SELECT * FROM task_reminder_status_history WHERE task_id = ? ORDER BY changed_at ASC, id ASC");
        $stmtHist->execute([$task_id]);
        $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($history as &$h) {
            $h['formatted_time'] = date('d M Y, h:i A', strtotime($h['changed_at']));
        }

        return [
            'task' => $task,
            'assignments' => $assignments,
            'history' => $history,
            'is_creator' => $isCreator,
            'is_assignee' => $isAssignee,
            'is_super_admin' => $is_super_admin
        ];
    } catch (Exception $e) {
        error_log("task_reminders_get_details error: " . $e->getMessage());
        return null;
    }
}

/**
 * Task Reminders — Comprehensive Global History (Tab 3)
 */
function task_reminders_list_history(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array {
    task_reminders_ensure_schema($pdo);
    if (!reminders_table_exists($pdo)) {
        return [];
    }

    try {
        $where = [];
        $params = [];

        if (!empty($filters['event_type'])) {
            $where[] = "trsh.event_type = ?";
            $params[] = strtoupper(trim($filters['event_type']));
        }

        if (!empty($filters['task_id'])) {
            $where[] = "trsh.task_id = ?";
            $params[] = (int)$filters['task_id'];
        }

        if (!empty($filters['admin'])) {
            $where[] = "(trsh.changed_by_username = ? OR r.created_by_username = ? OR r.assigned_to_username = ?)";
            $params[] = $filters['admin'];
            $params[] = $filters['admin'];
            $params[] = $filters['admin'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "DATE(trsh.changed_at) >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "DATE(trsh.changed_at) <= ?";
            $params[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT trsh.*, r.title as task_title, r.remind_at, r.status as task_status,
                       r.created_by_username as creator, r.assigned_to_username as assignee,
                       tt.name as task_type_name
                FROM task_reminder_status_history trsh
                JOIN reminders r ON r.id = trsh.task_id
                LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
                {$whereClause}
                ORDER BY trsh.changed_at DESC, trsh.id DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['task_type_name'] = $r['task_type_name'] ?: 'General Task';
            $r['formatted_time'] = date('d M Y, h:i A', strtotime($r['changed_at']));
            $r['formatted_due'] = date('d M Y, h:i A', strtotime($r['remind_at']));
        }

        return $rows;
    } catch (Exception $e) {
        error_log("task_reminders_list_history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Task Creation (Mandatory task_type_id, Immutable Assignment & History).
 */
function task_reminders_create(PDO $pdo, array $data, int $creator_admin_id, string $creator_username): array {
    task_reminders_ensure_schema($pdo);
    $task_type_id = isset($data['task_type_id']) ? (int)$data['task_type_id'] : 0;
    $title = trim($data['title'] ?? '');
    $notes = trim($data['notes'] ?? '');
    $remind_at = trim($data['remind_at'] ?? '');
    $assigned_to_username = trim($data['assigned_to'] ?? $creator_username);

    if ($task_type_id <= 0) {
        return ['success' => false, 'message' => 'Task Type is required.'];
    }

    // Verify task type is active or exists
    $typeInfo = task_types_get_by_id($pdo, $task_type_id);
    if (!$typeInfo) {
        return ['success' => false, 'message' => 'Selected Task Type does not exist.'];
    }
    if (!$typeInfo['is_active']) {
        return ['success' => false, 'message' => 'Selected Task Type is inactive.'];
    }

    if ($title === '') {
        return ['success' => false, 'message' => 'Task / Activity title is required.'];
    }

    if ($remind_at === '' || !strtotime($remind_at)) {
        return ['success' => false, 'message' => 'Valid Due Date & Time is required.'];
    }

    // Standardize datetime
    $due_datetime = date('Y-m-d H:i:s', strtotime($remind_at));

    // Resolve Assignee Admin ID
    $assigneeIdent = task_reminder_get_admin_identity($pdo, $assigned_to_username);
    $assigned_to_admin_id = $assigneeIdent['id'];
    $assigned_to_username = $assigneeIdent['username'] ?: $assigned_to_username;

    try {
        $nowDate = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            INSERT INTO reminders (
                task_type_id, title, notes, remind_at,
                created_by_admin_id, created_by_username, created_by,
                assigned_by_admin_id, assigned_by_username,
                assigned_to_admin_id, assigned_to_username, assigned_to,
                assigned_at, status, email_sent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?)
        ");
        $stmt->execute([
            $task_type_id, $title, $notes, $due_datetime,
            $creator_admin_id, $creator_username, $creator_username,
            $creator_admin_id, $creator_username,
            $assigned_to_admin_id, $assigned_to_username, $assigned_to_username,
            $nowDate, $nowDate
        ]);
        $taskId = (int)$pdo->lastInsertId();

        // 1. Insert Initial Assignment Record
        $stmtAss = $pdo->prepare("
            INSERT INTO task_reminder_assignments (
                task_id, assigned_by_admin_id, assigned_by_username,
                assigned_to_admin_id, assigned_to_username,
                assigned_at, is_current
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtAss->execute([
            $taskId, $creator_admin_id, $creator_username,
            $assigned_to_admin_id, $assigned_to_username, $nowDate
        ]);

        // 2. Insert CREATED Lifecycle Event in History
        $detailsJson = json_encode([
            'task_type_id' => $task_type_id,
            'task_type_name' => $typeInfo['name'],
            'title' => $title,
            'remind_at' => $due_datetime,
            'assigned_to' => $assigned_to_username
        ]);

        $stmtHist = $pdo->prepare("
            INSERT INTO task_reminder_status_history (
                task_id, event_type, old_status, new_status,
                changed_by_admin_id, changed_by_username, remarks, details_json, changed_at
            ) VALUES (?, 'CREATED', NULL, 'pending', ?, ?, 'Task created and assigned', ?, ?)
        ");
        $stmtHist->execute([$taskId, $creator_admin_id, $creator_username, $detailsJson, $nowDate]);

        // 3. Create Persistent Notification for Assignee if assigned to another admin
        if ($assigned_to_username !== $creator_username && $assigned_to_username !== '__ALL__') {
            try {
                $eventKey = 'assigned:' . date('YmdHis');
                $stmtNotif = $pdo->prepare("
                    INSERT INTO task_reminder_notifications (
                        task_id, recipient_admin_id, recipient_username,
                        sender_admin_id, sender_username,
                        notification_type, event_key, message, is_read, created_at
                    ) VALUES (?, ?, ?, ?, ?, 'TASK_ASSIGNED', ?, ?, 0, ?)
                ");
                $stmtNotif->execute([
                    $taskId, $assigned_to_admin_id, $assigned_to_username,
                    $creator_admin_id, $creator_username,
                    $eventKey, "You have been assigned a new task: {$title} by {$creator_username}",
                    $nowDate
                ]);
            } catch (Exception $eNotif) {}
        }

        return [
            'success' => true,
            'task_id' => $taskId,
            'message' => 'Task Reminder created successfully.'
        ];
    } catch (Exception $e) {
        error_log("task_reminders_create error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create task: ' . $e->getMessage()];
    }
}

/**
 * Task Editing (Creator or Super Admin can edit Type, Title, Notes, Due Time, Assignee).
 * Terminal states (Completed, Cancelled) cannot be edited.
 */
function task_reminders_edit(PDO $pdo, int $task_id, array $data, int $editor_admin_id, string $editor_username, bool $is_super_admin = false): array {
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return ['success' => false, 'message' => 'Invalid task ID.'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $oldTask = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldTask) {
            return ['success' => false, 'message' => 'Task not found.'];
        }

        // Rule 5: Completed and Cancelled tasks are terminal states and cannot be edited
        if ($oldTask['status'] === 'completed' || $oldTask['status'] === 'cancelled') {
            return ['success' => false, 'message' => "Cannot edit a {$oldTask['status']} task."];
        }

        // Authorization check: Only Task Creator or Super Admin can edit
        $isCreator = ($oldTask['created_by_admin_id'] == $editor_admin_id || $oldTask['created_by_username'] === $editor_username || $oldTask['created_by'] === $editor_username);
        if (!$is_super_admin && !$isCreator) {
            return ['success' => false, 'message' => 'You are not authorized to edit this task.'];
        }

        $task_type_id = isset($data['task_type_id']) ? (int)$data['task_type_id'] : (int)$oldTask['task_type_id'];
        $title = isset($data['title']) ? trim($data['title']) : $oldTask['title'];
        $notes = isset($data['notes']) ? trim($data['notes']) : $oldTask['notes'];
        $remind_at = isset($data['remind_at']) && trim($data['remind_at']) ? date('Y-m-d H:i:s', strtotime($data['remind_at'])) : $oldTask['remind_at'];
        $new_assignee_username = isset($data['assigned_to']) ? trim($data['assigned_to']) : $oldTask['assigned_to_username'];

        if ($task_type_id <= 0) {
            return ['success' => false, 'message' => 'Task Type is required.'];
        }
        if ($title === '') {
            return ['success' => false, 'message' => 'Task title is required.'];
        }

        $changes = [];
        if ($oldTask['task_type_id'] != $task_type_id) {
            $changes['task_type_id'] = ['old' => $oldTask['task_type_id'], 'new' => $task_type_id];
        }
        if ($oldTask['title'] !== $title) {
            $changes['title'] = ['old' => $oldTask['title'], 'new' => $title];
        }
        if ($oldTask['notes'] !== $notes) {
            $changes['notes'] = ['old' => $oldTask['notes'], 'new' => $notes];
        }
        if ($oldTask['remind_at'] !== $remind_at) {
            $changes['remind_at'] = ['old' => $oldTask['remind_at'], 'new' => $remind_at];
        }

        // Check if reassignment occurred as part of edit
        $assigneeChanged = false;
        if (!empty($new_assignee_username) && $new_assignee_username !== $oldTask['assigned_to_username'] && $new_assignee_username !== $oldTask['assigned_to']) {
            $assigneeChanged = true;
            $newAssigneeIdent = task_reminder_get_admin_identity($pdo, $new_assignee_username);
            $new_assignee_admin_id = $newAssigneeIdent['id'];
            $new_assignee_username = $newAssigneeIdent['username'] ?: $new_assignee_username;
            $changes['assigned_to'] = ['old' => $oldTask['assigned_to_username'], 'new' => $new_assignee_username];
        } else {
            $new_assignee_admin_id = $oldTask['assigned_to_admin_id'];
            $new_assignee_username = $oldTask['assigned_to_username'];
        }

        $nowDate = date('Y-m-d H:i:s');
        // Update reminders table
        $stmtUpd = $pdo->prepare("
            UPDATE reminders SET
                task_type_id = ?, title = ?, notes = ?, remind_at = ?,
                assigned_to_admin_id = ?, assigned_to_username = ?, assigned_to = ?,
                email_sent = (CASE WHEN remind_at != ? THEN 0 ELSE email_sent END),
                last_status_updated_at = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([
            $task_type_id, $title, $notes, $remind_at,
            $new_assignee_admin_id, $new_assignee_username, $new_assignee_username,
            $oldTask['remind_at'], $nowDate, $task_id
        ]);

        // If assignee changed, update assignment history
        if ($assigneeChanged) {
            $pdo->prepare("UPDATE task_reminder_assignments SET is_current = 0, ended_at = ? WHERE task_id = ? AND is_current = 1")->execute([$nowDate, $task_id]);
            $stmtAss = $pdo->prepare("
                INSERT INTO task_reminder_assignments (
                    task_id, assigned_by_admin_id, assigned_by_username,
                    assigned_to_admin_id, assigned_to_username,
                    assigned_at, is_current
                ) VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmtAss->execute([
                $task_id, $editor_admin_id, $editor_username,
                $new_assignee_admin_id, $new_assignee_username, $nowDate
            ]);
        }

        // Log EDITED history event
        $detailsJson = json_encode($changes);
        $stmtHist = $pdo->prepare("
            INSERT INTO task_reminder_status_history (
                task_id, event_type, old_status, new_status,
                changed_by_admin_id, changed_by_username, remarks, details_json, changed_at
            ) VALUES (?, 'EDITED', ?, ?, ?, ?, 'Task details updated', ?, ?)
        ");
        $stmtHist->execute([
            $task_id, $oldTask['status'], $oldTask['status'],
            $editor_admin_id, $editor_username, $detailsJson, $nowDate
        ]);

        return ['success' => true, 'message' => 'Task updated successfully.'];
    } catch (Exception $e) {
        error_log("task_reminders_edit error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to edit task: ' . $e->getMessage()];
    }
}

/**
 * Task Reassignment (Immutable assignment record updates).
 */
function task_reminders_reassign(PDO $pdo, int $task_id, string $new_assignee_username, int $assigner_admin_id, string $assigner_username, bool $is_super_admin = false): array {
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return ['success' => false, 'message' => 'Invalid task ID.'];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'message' => 'Task not found.'];
        }

        if ($task['status'] === 'completed' || $task['status'] === 'cancelled') {
            return ['success' => false, 'message' => "Cannot reassign a {$task['status']} task."];
        }

        $isCreator = ($task['created_by_admin_id'] == $assigner_admin_id || $task['created_by_username'] === $assigner_username || $task['created_by'] === $assigner_username);
        if (!$is_super_admin && !$isCreator) {
            return ['success' => false, 'message' => 'Only the task creator or super admin can reassign this task.'];
        }

        $newAssigneeIdent = task_reminder_get_admin_identity($pdo, $new_assignee_username);
        $new_assignee_admin_id = $newAssigneeIdent['id'];
        $new_assignee_username = $newAssigneeIdent['username'] ?: $new_assignee_username;

        if ($task['assigned_to_username'] === $new_assignee_username) {
            return ['success' => false, 'message' => 'Task is already assigned to this admin.'];
        }

        $nowDate = date('Y-m-d H:i:s');
        // 1. Mark previous current assignment ended
        $pdo->prepare("UPDATE task_reminder_assignments SET is_current = 0, ended_at = ? WHERE task_id = ? AND is_current = 1")->execute([$nowDate, $task_id]);

        // 2. Insert new assignment
        $stmtAss = $pdo->prepare("
            INSERT INTO task_reminder_assignments (
                task_id, assigned_by_admin_id, assigned_by_username,
                assigned_to_admin_id, assigned_to_username,
                assigned_at, is_current
            ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtAss->execute([
            $task_id, $assigner_admin_id, $assigner_username,
            $new_assignee_admin_id, $new_assignee_username, $nowDate
        ]);

        // 3. Update reminders record
        $stmtUpd = $pdo->prepare("
            UPDATE reminders SET
                assigned_by_admin_id = ?, assigned_by_username = ?,
                assigned_to_admin_id = ?, assigned_to_username = ?, assigned_to = ?,
                assigned_at = ?, last_status_updated_at = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([
            $assigner_admin_id, $assigner_username,
            $new_assignee_admin_id, $new_assignee_username, $new_assignee_username,
            $nowDate, $nowDate, $task_id
        ]);

        // 4. Log REASSIGNED lifecycle history
        $detailsJson = json_encode([
            'old_assignee' => $task['assigned_to_username'],
            'new_assignee' => $new_assignee_username
        ]);
        $stmtHist = $pdo->prepare("
            INSERT INTO task_reminder_status_history (
                task_id, event_type, old_status, new_status,
                changed_by_admin_id, changed_by_username, remarks, details_json, changed_at
            ) VALUES (?, 'REASSIGNED', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtHist->execute([
            $task_id, $task['status'], $task['status'],
            $assigner_admin_id, $assigner_username,
            "Reassigned from {$task['assigned_to_username']} to {$new_assignee_username}",
            $detailsJson, $nowDate
        ]);

        // 5. Send notification to new assignee
        try {
            $eventKey = 'reassigned:' . date('YmdHis');
            $stmtNotif = $pdo->prepare("
                INSERT INTO task_reminder_notifications (
                    task_id, recipient_admin_id, recipient_username,
                    sender_admin_id, sender_username,
                    notification_type, event_key, message, is_read, created_at
                ) VALUES (?, ?, ?, ?, ?, 'TASK_REASSIGNED', ?, ?, 0, ?)
            ");
            $stmtNotif->execute([
                $task_id, $new_assignee_admin_id, $new_assignee_username,
                $assigner_admin_id, $assigner_username,
                $eventKey, "Task '{$task['title']}' has been reassigned to you by {$assigner_username}",
                $nowDate
            ]);
        } catch (Exception $eN) {}

        return ['success' => true, 'message' => "Task reassigned to {$new_assignee_username} successfully."];
    } catch (Exception $e) {
        error_log("task_reminders_reassign error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to reassign task: ' . $e->getMessage()];
    }
}

/**
 * Task Postpone (Updates due time, resets email flag, logs POSTPONED with old/new due time and reason).
 */
function task_reminders_postpone(PDO $pdo, int $task_id, string $new_remind_at, string $reason, int $admin_id, string $admin_username, bool $is_super_admin = false): array {
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return ['success' => false, 'message' => 'Invalid task ID.'];
    }

    if (empty($new_remind_at) || !strtotime($new_remind_at)) {
        return ['success' => false, 'message' => 'Valid new due date and time is required.'];
    }

    $formattedNewTime = date('Y-m-d H:i:s', strtotime($new_remind_at));

    try {
        $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'message' => 'Task not found.'];
        }

        if ($task['status'] === 'completed' || $task['status'] === 'cancelled') {
            return ['success' => false, 'message' => "Cannot postpone a {$task['status']} task."];
        }

        // Authorization: Assignee, Creator, or Super Admin
        $isCreator = ($task['created_by_admin_id'] == $admin_id || $task['created_by_username'] === $admin_username || $task['created_by'] === $admin_username);
        $isAssignee = ($task['assigned_to_admin_id'] == $admin_id || $task['assigned_to_username'] === $admin_username || $task['assigned_to'] === $admin_username || $task['assigned_to'] === '__ALL__');

        if (!$is_super_admin && !$isCreator && !$isAssignee) {
            return ['success' => false, 'message' => 'You are not authorized to postpone this task.'];
        }

        $nowDate = date('Y-m-d H:i:s');
        $oldRemindAt = $task['remind_at'];

        // Update reminder row
        $stmtUpd = $pdo->prepare("
            UPDATE reminders SET
                remind_at = ?, email_sent = 0, snooze_until = NULL,
                latest_remarks = (CASE WHEN ? != '' THEN ? ELSE latest_remarks END),
                last_status_updated_at = ?
            WHERE id = ?
        ");
        $stmtUpd->execute([$formattedNewTime, $reason, $reason, $nowDate, $task_id]);

        // Log POSTPONED history event
        $detailsJson = json_encode([
            'old_remind_at' => $oldRemindAt,
            'new_remind_at' => $formattedNewTime,
            'reason' => $reason
        ]);
        $stmtHist = $pdo->prepare("
            INSERT INTO task_reminder_status_history (
                task_id, event_type, old_status, new_status,
                changed_by_admin_id, changed_by_username, remarks, details_json, changed_at
            ) VALUES (?, 'POSTPONED', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtHist->execute([
            $task_id, $task['status'], $task['status'],
            $admin_id, $admin_username,
            $reason ? "Postponed: " . $reason : "Postponed to " . date('d M, h:i A', strtotime($formattedNewTime)),
            $detailsJson, $nowDate
        ]);

        return [
            'success' => true,
            'new_remind_at' => $formattedNewTime,
            'formatted_time' => date('d M Y, h:i A', strtotime($formattedNewTime)),
            'message' => 'Task postponed successfully.'
        ];
    } catch (Exception $e) {
        error_log("task_reminders_postpone error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to postpone task: ' . $e->getMessage()];
    }
}

/**
 * Task Status Transitions (Pending -> In Progress, In Progress -> Completed, Cancelled).
 * When completed: permanent completed_at timestamp, completion notification to Original Creator.
 */
function task_reminders_update_status(PDO $pdo, int $task_id, string $new_status, string $remarks, int $admin_id, string $admin_username, bool $is_super_admin = false): array {
    if (!reminders_table_exists($pdo) || $task_id <= 0) {
        return ['success' => false, 'message' => 'Invalid task ID.'];
    }

    $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
    $new_status = strtolower(trim($new_status));
    if (!in_array($new_status, $valid_statuses, true)) {
        return ['success' => false, 'message' => 'Invalid status transition.'];
    }

    try {
        $nowDate = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT * FROM reminders WHERE id = ? LIMIT 1");
        $stmt->execute([$task_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return ['success' => false, 'message' => 'Task not found.'];
        }

        // Terminal state enforcement: Completed and Cancelled tasks cannot be altered
        if ($task['status'] === 'completed' || $task['status'] === 'cancelled') {
            return ['success' => false, 'message' => "Cannot change status of a {$task['status']} task."];
        }

        $old_status = $task['status'];
        if ($old_status === $new_status) {
            // Adding a remark without changing status
            if ($remarks !== '') {
                $stmtHist = $pdo->prepare("
                    INSERT INTO task_reminder_status_history (
                        task_id, event_type, old_status, new_status,
                        changed_by_admin_id, changed_by_username, remarks, changed_at
                    ) VALUES (?, 'REMARK_ADDED', ?, ?, ?, ?, ?, ?)
                ");
                $stmtHist->execute([$task_id, $old_status, $new_status, $admin_id, $admin_username, $remarks, $nowDate]);
                $pdo->prepare("UPDATE reminders SET latest_remarks = ?, last_status_updated_at = ? WHERE id = ?")->execute([$remarks, $nowDate, $task_id]);
                return ['success' => true, 'message' => 'Remark added successfully.'];
            }
            return ['success' => true, 'message' => 'Status unchanged.'];
        }

        // Authorization: Assignee, Creator, or Super Admin
        $isCreator = ($task['created_by_admin_id'] == $admin_id || $task['created_by_username'] === $admin_username || $task['created_by'] === $admin_username);
        $isAssignee = ($task['assigned_to_admin_id'] == $admin_id || $task['assigned_to_username'] === $admin_username || $task['assigned_to'] === $admin_username || $task['assigned_to'] === '__ALL__');

        if (!$is_super_admin && !$isCreator && !$isAssignee) {
            return ['success' => false, 'message' => 'You are not authorized to update this task.'];
        }

        $event_type = 'STARTED';
        if ($new_status === 'completed') {
            $event_type = 'COMPLETED';
        } elseif ($new_status === 'cancelled') {
            $event_type = 'CANCELLED';
        }

        // Update reminders record
        if ($new_status === 'completed') {
            $stmtUpd = $pdo->prepare("
                UPDATE reminders SET
                    status = 'completed',
                    completed_by_admin_id = ?,
                    completed_by_username = ?,
                    completed_by = ?,
                    completed_at = ?,
                    latest_remarks = (CASE WHEN ? != '' THEN ? ELSE latest_remarks END),
                    last_status_updated_at = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([$admin_id, $admin_username, $admin_username, $nowDate, $remarks, $remarks, $nowDate, $task_id]);
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE reminders SET
                    status = ?,
                    latest_remarks = (CASE WHEN ? != '' THEN ? ELSE latest_remarks END),
                    last_status_updated_at = ?
                WHERE id = ?
            ");
            $stmtUpd->execute([$new_status, $remarks, $remarks, $nowDate, $task_id]);
        }

        // Log Lifecycle Status History
        $stmtHist = $pdo->prepare("
            INSERT INTO task_reminder_status_history (
                task_id, event_type, old_status, new_status,
                changed_by_admin_id, changed_by_username, remarks, changed_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtHist->execute([$task_id, $event_type, $old_status, $new_status, $admin_id, $admin_username, $remarks, $nowDate]);

        // If COMPLETED, generate persistent TASK_COMPLETED notification for Task Creator
        if ($new_status === 'completed') {
            $creator_admin_id = $task['created_by_admin_id'];
            $creator_username = $task['created_by_username'] ?: $task['created_by'];

            // Only send if completer is not the creator or if creator is valid
            if (!empty($creator_username) && $creator_username !== $admin_username) {
                try {
                    $eventKey = 'completed:' . $task_id . ':' . date('YmdHi');
                    $notifMsg = "Task '{$task['title']}' was completed by {$admin_username}" . ($remarks ? ": \"{$remarks}\"" : ".");
                    $stmtNotif = $pdo->prepare("
                        INSERT INTO task_reminder_notifications (
                            task_id, recipient_admin_id, recipient_username,
                            sender_admin_id, sender_username,
                            notification_type, event_key, message, is_read, created_at
                        ) VALUES (?, ?, ?, ?, ?, 'TASK_COMPLETED', ?, ?, 0, ?)
                    ");
                    $stmtNotif->execute([
                        $task_id, $creator_admin_id, $creator_username,
                        $admin_id, $admin_username,
                        $eventKey, $notifMsg, $nowDate
                    ]);
                } catch (Exception $eNotif) {
                    error_log("Completion notification error: " . $eNotif->getMessage());
                }
            }
        }

        return [
            'success' => true,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'message' => "Task status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . "."
        ];
    } catch (Exception $e) {
        error_log("task_reminders_update_status error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()];
    }
}

/**
 * Fetch unread notifications for current admin (for popups & badge).
 */
function task_reminders_get_unread_notifications(PDO $pdo, int $admin_id, string $admin_username): array {
    if (!reminders_table_exists($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT n.*, r.title as task_title, r.status as task_status, tt.name as task_type_name
            FROM task_reminder_notifications n
            JOIN reminders r ON r.id = n.task_id
            LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
            WHERE (n.recipient_admin_id = ? OR n.recipient_username = ?)
            AND n.is_read = 0 AND n.is_dismissed = 0
            ORDER BY n.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$admin_id, $admin_username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['formatted_time'] = date('d M Y, h:i A', strtotime($r['created_at']));
        }
        return $rows;
    } catch (Exception $e) {
        error_log("task_reminders_get_unread_notifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * Dismiss / Mark Read a notification.
 */
function task_reminders_dismiss_notification(PDO $pdo, int $notification_id, int $admin_id, string $admin_username): bool {
    try {
        $nowDate = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE task_reminder_notifications SET is_read = 1, is_dismissed = 1, read_at = ?
                               WHERE id = ? AND (recipient_admin_id = ? OR recipient_username = ?)");
        return $stmt->execute([$nowDate, $notification_id, $admin_id, $admin_username]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Backward-Compatible Wrappers for Legacy Subsystems
 */
function reminders_for($pdo, $admin, $statuses = ['pending']) {
    return task_reminders_list_my_tasks($pdo, 0, $admin, ['status' => implode(',', $statuses)]);
}

function reminders_due($pdo, $admin) {
    if (!reminders_table_exists($pdo)) return [];
    try {
        $nowDate = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            SELECT r.*, tt.name as task_type_name FROM reminders r
            LEFT JOIN task_reminder_types tt ON tt.id = r.task_type_id
            WHERE r.status IN ('pending', 'in_progress') AND r.remind_at <= ?
            AND (r.assigned_to = ? OR r.assigned_to_username = ? OR r.assigned_to = '__ALL__')
            ORDER BY r.remind_at ASC
        ");
        $stmt->execute([$nowDate, $admin, $admin]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function reminders_send_due_emails($pdo) {
    if (!reminders_table_exists($pdo)) return;
    try {
        $nowDate = date('Y-m-d H:i:s');
        $rows = $pdo->query("SELECT * FROM reminders WHERE status IN ('pending', 'in_progress') AND email_sent = 0 AND remind_at <= '{$nowDate}'")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;

        foreach ($rows as $r) {
            $recipients = [];
            $assignedTo = $r['assigned_to_username'] ?: $r['assigned_to'];
            if ($assignedTo === '__ALL__') {
                try {
                    foreach ($pdo->query("SELECT email FROM admins WHERE status = 'active' AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_COLUMN) as $em) {
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
                    }
                } catch (Exception $e) {}
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT email FROM admins WHERE username = ? LIMIT 1");
                    $stmt->execute([$assignedTo]);
                    $em = $stmt->fetchColumn();
                    if ($em && filter_var($em, FILTER_VALIDATE_EMAIL)) $recipients[] = $em;
                } catch (Exception $e) {}
            }

            if ($recipients) {
                reminders_email($recipients, $r);
            }
            $pdo->prepare("UPDATE reminders SET email_sent = 1 WHERE id = ?")->execute([$r['id']]);
        }
    } catch (Exception $e) {
        error_log('reminders_send_due_emails: ' . $e->getMessage());
    }
}

function reminders_email($recipients, $r) {
    $to = implode(', ', $recipients);
    $when = date('d M Y, h:i A', strtotime($r['remind_at']));
    $title = htmlspecialchars($r['title']);
    $notes = nl2br(htmlspecialchars($r['notes'] ?? ''));
    $subject = '=?UTF-8?B?' . base64_encode('Task Reminder: ' . $r['title'] . ' | PEPP Learning') . '?=';

    $html = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f5f5f4;font-family:Segoe UI,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4;padding:28px 12px;"><tr><td align="center">
<table role="presentation" width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e7e5e4;">
  <tr><td style="background:#E8980C;padding:22px 30px;">
      <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px;">pepp <span style="font-weight:400;font-size:12px;letter-spacing:3px;">LEARNING</span></div>
      <div style="font-size:12px;color:rgba(255,255,255,.85);margin-top:2px;">Task Reminders &amp; Accountability</div>
  </td></tr>
  <tr><td style="padding:28px 30px 8px;">
      <div style="display:inline-block;background:#fef3c7;color:#b45309;font-size:12px;font-weight:700;border-radius:50px;padding:5px 14px;">&#9200; Task Due Alert</div>
      <h1 style="font-size:19px;color:#1f2937;margin:16px 0 6px;">' . $title . '</h1>
      <p style="font-size:13px;color:#9ca3af;margin:0 0 14px;">Scheduled for ' . $when . '</p>
      ' . ($notes ? '<div style="font-size:14px;color:#374151;line-height:1.6;background:#fafaf9;border:1px solid #e7e5e4;border-radius:10px;padding:14px 16px;">' . $notes . '</div>' : '') . '
  </td></tr>
  <tr><td style="padding:14px 30px 26px;">
      <p style="font-size:12.5px;color:#9ca3af;line-height:1.6;margin:0;">Open the PEPP admin console to update status, postpone, or complete this task.<br>This mailbox is not monitored - please do not reply.</p>
  </td></tr>
  <tr><td style="background:#1c1917;padding:14px 30px;text-align:center;">
      <div style="font-size:11px;color:#a8a29e;">&copy; ' . date('Y') . ' PEPP Learning - Labinc Education Pvt. Ltd.</div>
  </td></tr>
</table></td></tr></table></body></html>';

    $text = "Task Reminder Due - PEPP Learning\n\n{$r['title']}\nScheduled: {$when}\n\n" . ($r['notes'] ?? '') . "\n\nOpen the PEPP admin console to manage this task.";

    require_once __DIR__ . '/mailer.php';
    try {
        pepp_mail($to, $subject, $html, $text, [], 'noreply@pepplearning.in', 'PEPP Learning');
    } catch (Exception $e) {
        error_log('reminder mail: ' . $e->getMessage());
    }
}
