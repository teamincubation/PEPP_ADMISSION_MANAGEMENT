<?php
/**
 * PEPP Learning ERP — Staff application-type helper.
 *
 * Central place for the staff architecture rules:
 *
 *  - employees.application_for ∈ {employee, faculty, intern}
 *  - Employee + Intern  = generic ERP staff (Employee Management directory,
 *                         admin linking, mentor reports, etc.)
 *  - Faculty            = ISOLATED. Operational faculty data lives ONLY in
 *                         faculties.php and sessions.php. Faculty approval still
 *                         happens in Employee Management → Registration Requests.
 *  - Custom fields are scoped by application_for (employee|faculty|intern).
 *
 * All functions here are side-effect free unless stated (ensure_staff_type_schema).
 */

if (!defined('STAFF_APPLICATION_TYPES')) {
    define('STAFF_APPLICATION_TYPES', ['employee', 'faculty', 'intern']);
}
if (!defined('STAFF_CUSTOM_FIELD_APPLICATION_TYPES')) {
    define('STAFF_CUSTOM_FIELD_APPLICATION_TYPES', ['employee', 'faculty', 'intern', 'guest_' . 'faculty']);
}
if (!defined('STAFF_GENERIC_TYPES')) {
    /** Types that may appear in generic ERP staff modules. Faculty is excluded. */
    define('STAFF_GENERIC_TYPES', ['employee', 'intern']);
}

if (!function_exists('staff_normalize_type')) {
    /** Returns a valid application type or $default ('' = invalid). */
    function staff_normalize_type($value, string $default = ''): string {
        $v = strtolower(trim((string)$value));
        return in_array($v, STAFF_APPLICATION_TYPES, true) ? $v : $default;
    }
}

if (!function_exists('staff_normalize_custom_field_type')) {
    /** Returns a valid custom field application type or $default ('' = invalid). */
    function staff_normalize_custom_field_type($value, string $default = ''): string {
        $v = strtolower(trim((string)$value));
        return in_array($v, STAFF_CUSTOM_FIELD_APPLICATION_TYPES, true) ? $v : $default;
    }
}

if (!function_exists('staff_is_generic_type')) {
    /** True for employee/intern; false for faculty / unknown. */
    function staff_is_generic_type($value): bool {
        return in_array(strtolower(trim((string)$value)), STAFF_GENERIC_TYPES, true);
    }
}

if (!function_exists('staff_generic_type_sql')) {
    /**
     * SQL predicate restricting an `employees` query to Employee/Intern records.
     * Usage: "... WHERE " . staff_generic_type_sql('e')
     */
    function staff_generic_type_sql(string $alias = ''): string {
        $col = ($alias !== '' ? $alias . '.' : '') . 'application_for';
        return $col . " IN ('employee','intern')";
    }
}

if (!function_exists('staff_custom_fields_has_type')) {
    /** Whether employee_custom_fields.application_for exists (schema-tolerant). */
    function staff_custom_fields_has_type(PDO $pdo): bool {
        try {
            $stmt = $pdo->query("SELECT * FROM employee_custom_fields LIMIT 0");
            for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
                $m = $stmt->getColumnMeta($i);
                if ($m && strtolower($m['name'] ?? '') === 'application_for') return true;
            }
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('staff_normalize_custom_field_rows')) {
    /** Ensures every custom field row carries a valid application_for and normalized column aliases. */
    function staff_normalize_custom_field_rows(array $rows): array {
        foreach ($rows as &$r) {
            $raw_app = strtolower(trim((string)($r['application_for'] ?? '')));
            if (in_array($raw_app, STAFF_CUSTOM_FIELD_APPLICATION_TYPES, true)) {
                $r['application_for'] = $raw_app;
            } else {
                $r['application_for'] = 'employee';
            }

            // Normalise field label / name aliases
            $lbl = trim((string)($r['field_label'] ?? $r['field_name'] ?? ''));
            if ($lbl === '') {
                $lbl = 'Field #' . ($r['id'] ?? '');
            }
            $r['field_label'] = $lbl;
            $r['field_name'] = $lbl;

            // Normalise dropdown / options aliases
            $opts = (string)($r['field_options'] ?? $r['dropdown_options'] ?? '');
            $r['field_options'] = $opts;
            $r['dropdown_options'] = $opts;

            // Normalise field key
            $key = trim((string)($r['field_key'] ?? ''));
            if ($key === '') {
                $key = 'cf_' . ($r['id'] ?? '');
            }
            $r['field_key'] = $key;
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('staff_load_active_custom_fields')) {
    /**
     * Active custom fields for ALL types (each row carries application_for) —
     * used by the public form so every field can be rendered with a
     * data-application-for attribute and filtered client-side, and
     * re-filtered server-side by staff_validate_custom_fields().
     */
    function staff_load_active_custom_fields(PDO $pdo): array {
        try {
            $rows = $pdo->query("SELECT * FROM employee_custom_fields WHERE status = 'active' ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return staff_normalize_custom_field_rows($rows);
    }
}

if (!function_exists('staff_validate_custom_fields')) {
    /**
     * Server-side validation of submitted custom fields, SCOPED to the selected
     * application type. Fields belonging to another type are ignored entirely:
     * they are neither required, validated, nor stored (so a malicious POST of
     * another role's cf_<id> is dropped).
     *
     * @param array  $fields          rows from staff_load_active_custom_fields()
     * @param string $application_for selected, already-validated type
     * @param array  $post            usually $_POST
     * @return array ['errors' => string[], 'data' => [field_id => value], 'json' => ?string]
     */
    function staff_validate_custom_fields(array $fields, string $application_for, array $post): array {
        $errors = [];
        $data = [];
        $app_for_norm = staff_normalize_custom_field_type($application_for);
        if ($app_for_norm === '') {
            return ['valid' => true, 'errors' => [], 'data' => [], 'json' => null];
        }

        foreach (staff_normalize_custom_field_rows($fields) as $cf) {
            if ($cf['application_for'] !== $app_for_norm) {
                continue; // belongs to a different staff type → ignore
            }
            $label = htmlspecialchars((string)($cf['field_label'] ?? ''));
            $fid = $cf['id'] ?? '';
            $fkey = $cf['field_key'] ?? '';

            // Check cf_<id>, bare field_key, or cf_<key>
            $cf_val = '';
            if (isset($post['cf_' . $fid])) {
                $cf_val = trim((string)$post['cf_' . $fid]);
            } elseif ($fkey !== '' && isset($post[$fkey])) {
                $cf_val = trim((string)$post[$fkey]);
            } elseif ($fkey !== '' && isset($post['cf_' . $fkey])) {
                $cf_val = trim((string)$post['cf_' . $fkey]);
            }

            if (!empty($cf['is_required']) && $cf_val === '') {
                $errors[] = $label . ' is required.';
                continue;
            }
            if ($cf_val === '') continue; // optional and empty

            switch ($cf['field_type'] ?? 'text') {
                case 'email':
                    if (!filter_var($cf_val, FILTER_VALIDATE_EMAIL)) $errors[] = $label . ': invalid email.';
                    break;
                case 'number':
                    if (!is_numeric($cf_val)) $errors[] = $label . ': must be a number.';
                    break;
                case 'date':
                    if (!strtotime($cf_val)) $errors[] = $label . ': invalid date.';
                    break;
                case 'dropdown':
                    $opts_str = (string)($cf['field_options'] ?? '');
                    $allowed = array_map('trim', explode(',', $opts_str));
                    if (!in_array($cf_val, $allowed, true)) $errors[] = $label . ': invalid selection.';
                    break;
                case 'phone':
                    if (strlen(preg_replace('/\D/', '', $cf_val)) < 7) $errors[] = $label . ': invalid phone number.';
                    break;
            }
            $data[$fid] = $cf_val;
        }
        return [
            'valid'  => empty($errors),
            'errors' => $errors,
            'data'   => $data,
            'json'   => !empty($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ];
    }
}

if (!function_exists('staff_filter_custom_values_for_type')) {
    /**
     * Approval-time filter: from a registration's stored custom_field_values
     * keep only values whose field exists AND belongs to $application_for.
     * @return array [field_id => string value]
     */
    function staff_filter_custom_values_for_type(PDO $pdo, $stored, string $application_for): array {
        $vals = is_string($stored) ? json_decode($stored, true) : $stored;
        if (!is_array($vals)) return [];
        $app_for_norm = staff_normalize_custom_field_type($application_for, 'employee');
        try {
            $rows = $pdo->query("SELECT * FROM employee_custom_fields")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $valid = [];
        foreach (staff_normalize_custom_field_rows($rows) as $r) {
            if ($r['application_for'] === $app_for_norm) $valid[(int)$r['id']] = true;
        }
        $out = [];
        foreach ($vals as $fid => $fval) {
            $fid = (int)$fid;
            if (isset($valid[$fid]) && $fval !== '' && $fval !== null) $out[$fid] = (string)$fval;
        }
        return $out;
    }
}

if (!function_exists('staff_get_custom_values')) {
    /**
     * Custom fields (for $application_for) with the stored value of one
     * employees.id. Used by faculties.php to read Faculty custom values without
     * exposing the Faculty record through generic Employee Management.
     */
    function staff_get_custom_values(PDO $pdo, int $employee_record_id, string $application_for): array {
        $application_for = staff_normalize_type($application_for, 'employee');
        try {
            $stmt = $pdo->prepare("
                SELECT cf.*, cv.field_value
                FROM employee_custom_fields cf
                LEFT JOIN employee_custom_values cv ON cv.field_id = cf.id AND cv.employee_id = ?
                ORDER BY cf.sort_order ASC, cf.id ASC
            ");
            $stmt->execute([$employee_record_id]);
            $rows = staff_normalize_custom_field_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter($rows, fn($r) => ($r['status'] ?? 'active') === 'active' && $r['application_for'] === $application_for));
    }
}

if (!function_exists('staff_save_custom_values')) {
    /**
     * Upsert custom values for one employees.id. Only fields of $application_for are
     * accepted; anything else in $values is ignored. Returns number of values written.
     */
    function staff_save_custom_values(PDO $pdo, int $employee_record_id, string $application_for, array $values): int {
        $fields = staff_get_custom_values($pdo, $employee_record_id, $application_for);
        $allowed = [];
        foreach ($fields as $f) $allowed[(int)$f['id']] = $f;
        $written = 0;
        $del = $pdo->prepare("DELETE FROM employee_custom_values WHERE employee_id = ? AND field_id = ?");
        $ins = $pdo->prepare("INSERT INTO employee_custom_values (employee_id, field_id, field_value) VALUES (?,?,?)");
        foreach ($values as $fid => $val) {
            $fid = (int)$fid;
            if (!isset($allowed[$fid])) continue;
            $val = trim((string)$val);
            $del->execute([$employee_record_id, $fid]);
            if ($val !== '') { $ins->execute([$employee_record_id, $fid, $val]); $written++; }
        }
        return $written;
    }
}

if (!function_exists('staff_custom_field_has_data')) {
    /**
     * True if any submitted value exists for this custom field (approved staff values
     * OR pending/historic registration-request JSON). Used to block unsafe type changes.
     */
    function staff_custom_field_has_data(PDO $pdo, int $field_id): bool {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM employee_custom_values WHERE field_id = ? AND field_value IS NOT NULL AND field_value <> ''");
            $st->execute([$field_id]);
            if ((int)$st->fetchColumn() > 0) return true;
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM staff_registration_requests WHERE custom_field_values LIKE ?");
            $st->execute(['%"' . $field_id . '":%']);
            if ((int)$st->fetchColumn() > 0) return true;
        } catch (Throwable $e) {}
        return false;
    }
}

if (!function_exists('ensure_staff_type_schema')) {
    /**
     * Idempotent self-healing mirror of database-update-56.sql (MySQL only).
     *  1. employees: UNIQUE(email) uq_emp_email  ->  UNIQUE(email, application_for) uq_emp_email_type
     *     (new key is created FIRST, old key dropped only if it exists, and never if
     *      (email, application_for) duplicates exist — nothing is ever deleted)
     *  2. employee_custom_fields.application_for (legacy rows => 'employee')
     *  3. supporting indexes
     * @return array report of actions taken / skipped (for logging & tests)
     */
    function ensure_staff_type_schema(PDO $pdo): array {
        $report = [];
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return ['skipped' => 'non-mysql driver'];
            }
            $tbl = fn(string $t) => (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn();
            $idx = fn(string $t, string $i) => (bool)$pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t) . " AND index_name = " . $pdo->quote($i) . " LIMIT 1")->fetchColumn();
            $col = fn(string $t, string $c) => (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t) . " AND column_name = " . $pdo->quote($c) . " LIMIT 1")->fetchColumn();

            if ($tbl('employees')) {
                $dups = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM employees GROUP BY email, application_for HAVING COUNT(*) > 1) d")->fetchColumn();
                if ($dups > 0) {
                    $report['employees_unique'] = "SKIPPED: {$dups} duplicate (email, application_for) group(s) exist — old key kept, no data deleted";
                    error_log("ensure_staff_type_schema: {$dups} duplicate (email, application_for) groups in employees; uq_emp_email retained.");
                } else {
                    if (!$idx('employees', 'uq_emp_email_type')) {
                        $pdo->exec("ALTER TABLE `employees` ADD UNIQUE KEY `uq_emp_email_type` (`email`, `application_for`)");
                        $report['uq_emp_email_type'] = 'created';
                    }
                    if ($idx('employees', 'uq_emp_email_type') && $idx('employees', 'uq_emp_email')) {
                        $pdo->exec("ALTER TABLE `employees` DROP INDEX `uq_emp_email`");
                        $report['uq_emp_email'] = 'dropped';
                    }
                }
                if (!$idx('employees', 'idx_emp_type_status')) {
                    $pdo->exec("ALTER TABLE `employees` ADD INDEX `idx_emp_type_status` (`application_for`, `status`)");
                    $report['idx_emp_type_status'] = 'created';
                }
            }

            if ($tbl('employee_custom_fields')) {
                if (!$col('employee_custom_fields', 'application_for')) {
                    $pdo->exec("ALTER TABLE `employee_custom_fields` ADD COLUMN `application_for` VARCHAR(20) NOT NULL DEFAULT 'employee'");
                    $report['ecf_application_for'] = 'added';
                }
                $pdo->exec("UPDATE `employee_custom_fields` SET `application_for` = 'employee' WHERE `application_for` IS NULL OR `application_for` = ''");
                if (!$idx('employee_custom_fields', 'idx_ecf_type')) {
                    $pdo->exec("ALTER TABLE `employee_custom_fields` ADD INDEX `idx_ecf_type` (`application_for`, `status`)");
                    $report['idx_ecf_type'] = 'created';
                }
            }

            if ($tbl('admins')) {
                if (!$col('admins', 'can_view_bank_credentials')) {
                    $pdo->exec("ALTER TABLE `admins` ADD COLUMN `can_view_bank_credentials` TINYINT(1) NOT NULL DEFAULT 0");
                    $report['can_view_bank_credentials'] = 'added';
                }
                if (!$col('admins', 'can_copy_bank_credentials')) {
                    $pdo->exec("ALTER TABLE `admins` ADD COLUMN `can_copy_bank_credentials` TINYINT(1) NOT NULL DEFAULT 0");
                    $report['can_copy_bank_credentials'] = 'added';
                }
                $pdo->exec("UPDATE `admins` SET `can_view_bank_credentials` = 1, `can_copy_bank_credentials` = 1 WHERE `role` = 'super_admin'");
            }
        } catch (Throwable $e) {
            error_log('ensure_staff_type_schema error: ' . $e->getMessage());
            $report['error'] = $e->getMessage();
        }
        return $report;
    }
}

if (!function_exists('staff_mask_account_number')) {
    function staff_mask_account_number(?string $account): string {
        $clean = preg_replace('/\s+/', '', (string)$account);
        if ($clean === '') return '';
        $len = strlen($clean);
        if ($len <= 4) return str_repeat('X', $len);
        $last4 = substr($clean, -4);
        return 'XXXX XXXX ' . $last4;
    }
}

if (!function_exists('staff_mask_ifsc')) {
    function staff_mask_ifsc(?string $ifsc): string {
        $clean = trim((string)$ifsc);
        if ($clean === '') return '';
        return 'XXXX0000000';
    }
}

if (!function_exists('staff_mask_upi')) {
    function staff_mask_upi(?string $upi): string {
        $clean = trim((string)$upi);
        if ($clean === '') return '';
        return 'Restricted';
    }
}
