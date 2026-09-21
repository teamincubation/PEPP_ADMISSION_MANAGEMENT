<?php
/**
 * PEPP Learning ERP — Super Admin Task Reminders Work Report PDF Endpoint
 *
 * Streams an official print-quality vector PDF report for the Task Reminders module.
 * Restricted strictly to authorized Super Admins.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/reminders_helper.php';
require_once __DIR__ . '/includes/task_report_pdf.php';
require_once __DIR__ . '/includes/activity_logger.php';

// 1. Strict Security & Authorization Check
require_permission('task-reminders');

if (!is_super_admin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style="font-family:sans-serif;padding:40px;color:#334155;">';
    echo '<h2 style="color:#ef4444;">Access Denied</h2>';
    echo '<p>Only Super Administrators are authorized to export staff task work reports.</p>';
    echo '</body></html>';
    exit;
}

$current_username = get_admin_user() ?? 'superadmin';
$admin_identity = task_reminder_get_admin_identity($pdo, $current_username);
$current_admin_id = $admin_identity['id'];

// 2. Filter Extraction & Whitelist Sanitization
$event_type = strtoupper(trim((string)($_GET['event_type'] ?? '')));
$valid_events = ['CREATED', 'ASSIGNED', 'REASSIGNED', 'STARTED', 'POSTPONED', 'COMPLETED', 'CANCELLED'];
if (!in_array($event_type, $valid_events, true)) {
    $event_type = '';
}

$admin_filter = trim((string)($_GET['admin'] ?? ''));
if ($admin_filter !== '' && !preg_match('/^[a-zA-Z0-9_.-]+$/', $admin_filter)) {
    $admin_filter = '';
}

$date_preset = strtolower(trim((string)($_GET['date_preset'] ?? $_GET['date_filter'] ?? '')));
$valid_presets = ['today', 'tomorrow', 'this_week', 'custom'];
if (!in_array($date_preset, $valid_presets, true)) {
    $date_preset = '';
}

$date_from = trim((string)($_GET['date_from'] ?? ''));
if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = '';
}

$date_to = trim((string)($_GET['date_to'] ?? ''));
if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = '';
}

$filters = [
    'event_type'  => $event_type,
    'admin'       => $admin_filter,
    'date_preset' => $date_preset,
    'date_from'   => $date_from,
    'date_to'     => $date_to,
];

try {
    // 3. Aggregate Authoritative Report Dataset
    $reportData = task_reminders_get_history_report_data($pdo, $filters, $current_admin_id, $current_username, true);

    // 4. Render Pure-PHP Vector PDF Bytes
    $pdf_bytes = render_task_work_report_pdf($reportData);

    // 5. Audit Logging
    $logDetail = sprintf(
        'Exported Task Work Report: admin=%s, event=%s, range=%s (%d tasks)',
        $admin_filter ?: 'ALL',
        $event_type ?: 'ALL',
        $reportData['scope']['period_label'] ?? 'All Time',
        (int)($reportData['summary']['total_tasks'] ?? 0)
    );
    if (function_exists('log_activity_event')) {
        log_activity_event($pdo, [
            'admin_username' => $current_username,
            'admin_id'       => $current_admin_id,
            'action_type'    => 'task_work_report_exported',
            'module'         => 'task-reminders',
            'page'           => 'task-reminder-report-pdf.php',
            'details'        => $logDetail
        ]);
    }

    // 6. Set Streaming HTTP Headers
    $filenameSuffix = $admin_filter ? ('_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $admin_filter)) : '_all_admins';
    $filenameDate = date('Ymd_His');
    $filename = "task_work_report{$filenameSuffix}_{$filenameDate}.pdf";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf_bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf_bytes;
    exit;

} catch (Throwable $e) {
    error_log("task-reminder-report-pdf error: " . $e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>500 Internal Error</title></head><body style="font-family:sans-serif;padding:40px;color:#334155;">';
    echo '<h2 style="color:#ef4444;">Report Generation Failed</h2>';
    echo '<p>An unexpected error occurred while generating the Task Work Report PDF. Please try again or contact system support.</p>';
    echo '</body></html>';
    exit;
}
