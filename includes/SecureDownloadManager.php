<?php
/**
 * Secure Download & Monthly Backup Manager for PEPP ERP.
 */

class SecureDownloadManager {
    private static $tokenFile = __DIR__ . '/../config/activity_exports/tokens.json';

    /**
     * Helper to verify if a table exists in the database.
     */
    private static function tableExists($pdo, $t) {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                return (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
            }
            return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
        } catch (Exception $e) { return false; }
    }

    /**
     * Registers a new secure download token.
     */
    public static function registerToken($token, $filePath, $expiresAt) {
        $dir = dirname(self::$tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $data = [];
        if (file_exists(self::$tokenFile)) {
            $data = json_decode(file_get_contents(self::$tokenFile), true) ?: [];
        }
        $data[$token] = [
            'file_path' => $filePath,
            'expires_at' => $expiresAt,
            'created_at' => time()
        ];
        file_put_contents(self::$tokenFile, json_encode($data));
    }

    /**
     * Validates a token and returns the corresponding file path if valid.
     */
    public static function validateToken($token) {
        if (!file_exists(self::$tokenFile)) {
            return null;
        }
        $data = json_decode(file_get_contents(self::$tokenFile), true) ?: [];
        if (!isset($data[$token])) {
            return null;
        }
        $info = $data[$token];
        if ($info['expires_at'] !== null && time() > $info['expires_at']) {
            return null;
        }
        return $info['file_path'];
    }

    /**
     * Cleans up expired export files and their registered tokens.
     */
    public static function cleanExpired() {
        if (!file_exists(self::$tokenFile)) {
            return;
        }
        $data = json_decode(file_get_contents(self::$tokenFile), true) ?: [];
        $changed = false;
        foreach ($data as $token => $info) {
            if ($info['expires_at'] !== null && time() > $info['expires_at']) {
                if (file_exists($info['file_path'])) {
                    unlink($info['file_path']);
                }
                unset($data[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            file_put_contents(self::$tokenFile, json_encode($data));
        }
    }

    /**
     * Streams monthly activity backup records in chronological order.
     */
    public static function streamMonthlyBackup($pdo, $yearMonth, $outputFilePath) {
        $startDate = $yearMonth . '-01 00:00:00';
        $endDate = date('Y-m-t', strtotime($yearMonth . '-01')) . ' 23:59:59';

        $stmtLog = null;
        if (self::tableExists($pdo, 'admin_activity_log')) {
            $stmtLog = $pdo->prepare("SELECT created_at as at_time, admin_username as admin_name, action_type as act, details, 
                                             target_type, target_id, ip_address as ip, location as loc, 
                                             latitude as lat, longitude as lng, metadata as meta
                                      FROM admin_activity_log 
                                      WHERE created_at >= ? AND created_at <= ? 
                                      ORDER BY created_at DESC");
            $stmtLog->execute([$startDate, $endDate]);
        }

        $stmtTrack = null;
        if (self::tableExists($pdo, 'track_records')) {
            $stmtTrack = $pdo->prepare("SELECT performed_at as at_time, performed_by as admin_name, action_type as act, action_details as details, 
                                               user_id as student, null as ip, null as loc, 
                                               latitude as lat, longitude as lng, metadata as meta
                                        FROM track_records 
                                        WHERE performed_at >= ? AND performed_at <= ? 
                                        ORDER BY performed_at DESC");
            $stmtTrack->execute([$startDate, $endDate]);
        }

        $stmtWa = null;
        if (self::tableExists($pdo, 'whatsapp_notifications')) {
            $stmtWa = $pdo->prepare("SELECT created_at as at_time, sent_by as admin_name, 'whatsapp_message' as act, 
                                            student_name, phone, message,
                                            null as student, null as ip, null as loc, 
                                            latitude as lat, longitude as lng, metadata as meta
                                     FROM whatsapp_notifications 
                                     WHERE created_at >= ? AND created_at <= ? 
                                     ORDER BY created_at DESC");
            $stmtWa->execute([$startDate, $endDate]);
        }

        $out = fopen($outputFilePath, 'w');
        if (!$out) return false;

        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date & Time', 'Admin', 'Action', 'Details', 'Student ID', 'IP Address', 'Location', 'Latitude', 'Longitude', 'Metadata'], ',', '"', "\\");

        $rowLog = $stmtLog ? $stmtLog->fetch(PDO::FETCH_ASSOC) : null;
        $rowTrack = $stmtTrack ? $stmtTrack->fetch(PDO::FETCH_ASSOC) : null;
        $rowWa = $stmtWa ? $stmtWa->fetch(PDO::FETCH_ASSOC) : null;

        $totalRecords = 0;

        while ($rowLog || $rowTrack || $rowWa) {
            $timeLog = $rowLog ? strtotime($rowLog['at_time']) : -1;
            $timeTrack = $rowTrack ? strtotime($rowTrack['at_time']) : -1;
            $timeWa = $rowWa ? strtotime($rowWa['at_time']) : -1;

            $maxTime = max($timeLog, $timeTrack, $timeWa);
            if ($maxTime === -1) break;

            if ($maxTime === $timeLog) {
                $r = $rowLog;
                $rowLog = $stmtLog->fetch(PDO::FETCH_ASSOC);
                $student = ($r['target_type'] === 'student') ? $r['target_id'] : null;
                $details = $r['details'];
            } elseif ($maxTime === $timeTrack) {
                $r = $rowTrack;
                $rowTrack = $stmtTrack->fetch(PDO::FETCH_ASSOC);
                $student = $r['student'];
                $details = $r['details'];
            } else {
                $r = $rowWa;
                $rowWa = $stmtWa->fetch(PDO::FETCH_ASSOC);
                $student = null;
                $details = 'To ' . ($r['student_name'] ?: $r['phone']) . ': ' . mb_substr((string)$r['message'], 0, 160);
            }

            fputcsv($out, [
                $r['at_time'],
                $r['admin_name'],
                $r['act'],
                $details,
                $student,
                $r['ip'],
                $r['loc'],
                $r['lat'] ?? '',
                $r['lng'] ?? '',
                $r['meta'] ?? ''
            ], ',', '"', "\\");
            $totalRecords++;
        }

        fclose($out);
        return $totalRecords;
    }

    /**
     * Executes the monthly backup process automatically.
     */
    public static function runMonthlyBackupJob($pdo, $forceDate = null) {
        $today = $forceDate ?: date('Y-m-d');
        $dayOfMonth = (int)date('d', strtotime($today));

        // Runs only on the 1st of the month
        if ($dayOfMonth !== 1) {
            return false;
        }

        $targetMonth = date('Y-m', strtotime('first day of last month', strtotime($today)));

        // Idempotency check: has this month been backed up already?
        if (self::tableExists($pdo, 'admin_settings')) {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'activity_log_last_monthly_backup' LIMIT 1");
            $stmt->execute();
            $lastBackup = $stmt->fetchColumn();
            if ($lastBackup === $targetMonth) {
                error_log("SecureDownloadManager: Backup for {$targetMonth} already processed.");
                return false;
            }
        }

        // Generate backup file securely
        $exportDir = __DIR__ . '/../config/activity_exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filename = 'PEPP_Admin_Activity_Backup_' . $targetMonth . '.csv';
        $filePath = $exportDir . '/' . $filename;

        $totalRecords = self::streamMonthlyBackup($pdo, $targetMonth, $filePath);
        if ($totalRecords === false) {
            error_log("SecureDownloadManager: Monthly backup stream failed for {$targetMonth}.");
            return false;
        }

        // Register secure token (valid for 1 year)
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + (365 * 24 * 3600); // 1 year retention
        self::registerToken($token, $filePath, $expiresAt);

        // Send email to both admin emails
        // Read application base URL safely
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/admissions/cron-queue.php';
        $baseUrl = 'http://' . $host . dirname($scriptName);
        $downloadUrl = rtrim($baseUrl, '/') . '/activity-export-download.php?token=' . $token;

        $monthName = date('F Y', strtotime($targetMonth . '-01'));
        $subject = 'PEPP ERP – Monthly Admin Activity Backup – ' . $monthName;
        
        $html = '<h3>PEPP ERP Monthly Admin Activity Backup</h3>';
        $html .= '<p>The automated monthly activity backup has been completed successfully.</p>';
        $html .= '<p><strong>Backup Specifications:</strong></p>';
        $html .= '<ul>';
        $html .= '<li><strong>Backup Period:</strong> ' . htmlspecialchars($monthName) . '</li>';
        $html .= '<li><strong>Total Logs Backed Up:</strong> ' . $totalRecords . '</li>';
        $html .= '<li><strong>Generation Date/Time:</strong> ' . date('d M Y h:i A', strtotime($today)) . '</li>';
        $html .= '<li><strong>Link Expiration:</strong> 1 Year</li>';
        $html .= '</ul>';
        $html .= '<p><a href="' . htmlspecialchars($downloadUrl) . '" style="display:inline-block; padding:10px 20px; background:#7c3aed; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold;">Download Monthly Backup File</a></p>';
        $html .= '<p><small>If the button above does not work, copy and paste the following link into your browser:<br>' . htmlspecialchars($downloadUrl) . '</small></p>';

        require_once __DIR__ . '/mail_queue.php';
        $mail1 = pepp_enqueue_mail('incubation.ngo@gmail.com', $subject, $html, '', [], 'noreply@pepplearning.in', 'PEPP Learning', 10, 'monthly_backup_' . $targetMonth);
        $mail2 = pepp_enqueue_mail('office@pepplearning.com', $subject, $html, '', [], 'noreply@pepplearning.in', 'PEPP Learning', 10, 'monthly_backup_' . $targetMonth);

        if ($mail1 === false || $mail2 === false) {
            error_log("SecureDownloadManager: Monthly backup email queue insertion failed. Aborting backup completion registration.");
            return false;
        }

        // Update last backup configuration setting idempotency tracker
        if (self::tableExists($pdo, 'admin_settings')) {
            $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'activity_log_last_monthly_backup'");
            $stmt->execute();
            if ($stmt->fetchColumn() !== false) {
                $stmt = $pdo->prepare("UPDATE admin_settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_name = 'activity_log_last_monthly_backup'");
                $stmt->execute([$targetMonth]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO admin_settings (setting_name, setting_value, updated_at) VALUES ('activity_log_last_monthly_backup', ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$targetMonth]);
            }
        }

        error_log("SecureDownloadManager: Monthly backup completed for {$targetMonth} (records={$totalRecords}).");
        return true;
    }
}
