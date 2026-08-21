<?php
/**
 * PEPP ERP Git Deployment Audit & Fast-Forward Tool.
 * 
 * INSTRUCTIONS:
 * 1. Upload this file as `git-deploy-audit.php` to your production `/admissions/` directory.
 * 2. Visit for Audit: 
 *    https://pepplearning.in/admissions/git-deploy-audit.php?secret=PEPP_Audit_Secret_Token_2026
 * 3. Review the audit report. If there are no conflicts, perform the safe pull by visiting:
 *    https://pepplearning.in/admissions/git-deploy-audit.php?secret=PEPP_Audit_Secret_Token_2026&pull=1
 * 4. Copy the complete output and share it.
 * 5. IMMEDIATELY DELETE this file from the production server after use.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=UTF-8');

define('AUDIT_SECRET', 'PEPP_Audit_Secret_Token_2026');

// 1. Authentication Check
session_start();
$is_authenticated = false;

if (isset($_GET['secret']) && $_GET['secret'] === AUDIT_SECRET) {
    $is_authenticated = true;
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $is_authenticated = true;
}

if (!$is_authenticated) {
    http_response_code(403);
    echo "ERROR: Unauthorized access.\n";
    exit();
}

$repo_path = '/home/u361910773/domains/pepplearning.in/public_html/admissions';
if (!is_dir($repo_path)) {
    echo "ERROR: Directory $repo_path does not exist.\n";
    exit();
}

// Change to the git repository directory
chdir($repo_path);

echo "HOSTINGER DEPLOYMENT GIT AUDIT & FAST-FORWARD\n";
echo "============================================\n";
echo "Working Directory: " . getcwd() . "\n";
echo "Run Time          : " . date('Y-m-d H:i:s') . "\n\n";

// Fallback file and commit check (using raw PHP functions, bypasses disabled commands)
echo "--- FALLBACK DIAGNOSTICS (No command execution needed) ---\n";
if (file_exists('.git/refs/heads/main')) {
    $commit_hash = trim(file_get_contents('.git/refs/heads/main'));
    echo "Active Deployed Commit : " . $commit_hash . "\n";
} else {
    echo "Active Deployed Commit : (Unable to read .git/refs/heads/main)\n";
}

$files_to_check = [
    'includes/communication/Providers/WhatsAppCloudProvider.php',
    'whatsapp-marketing-templates.php',
    'git-deploy-audit.php'
];
foreach ($files_to_check as $f) {
    if (file_exists($f)) {
        echo str_pad($f, 60) . "Modified: " . date("Y-m-d H:i:s", filemtime($f)) . "\n";
    } else {
        echo str_pad($f, 60) . "MISSING\n";
    }
}
echo "--------------------------------------------------------\n\n";

// Helper function to run shell commands safely
// Helper function to run shell commands safely using proc_open
function run_cmd($cmd) {
    echo "$ {$cmd}\n";
    if (!function_exists('proc_open')) {
        echo "ERROR: proc_open is disabled on this server.\n";
        echo "--------------------------------------------------------\n";
        return '';
    }

    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];

    $process = @proc_open($cmd, $descriptorspec, $pipes);
    $output = '';

    if (is_resource($process)) {
        fclose($pipes[0]); // Close stdin immediately since we don't write input
        
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        
        proc_close($process);
        $output = $stdout . $stderr;
    } else {
        $output = "ERROR: Failed to run command using proc_open.\n";
    }

    echo ($output !== '') ? $output : "(no output)\n";
    echo "--------------------------------------------------------\n";
    return $output;
}

// 1. Run server-side audit
echo "--- 1. GIT STATUS BEFORE ---\n";
run_cmd('git status --short');

echo "--- 2. GIT BRANCH INFO ---\n";
run_cmd('git branch -vv');

echo "--- 3. LOCAL HEAD LOG ---\n";
run_cmd('git log -1 --oneline --format="%H - %ad - %s"');

echo "--- 4. FETCHING FROM ORIGIN ---\n";
run_cmd('git fetch origin 2>&1');

echo "--- 5. GIT STATUS AFTER FETCH ---\n";
$status_out = run_cmd('git status');

echo "--- 6. INCOMING CHANGES DIFF ---\n";
$diff_out = run_cmd('git diff --name-only HEAD..origin/main 2>&1');

// 2. Check for conflicts
echo "--- 7. CONFLICT CHECK ---\n";
$untracked_files = [
    'api/v1/communication/error_log',
    'debug-bg-output.txt',
    'error_log',
    'logo1.png',
    'uploads/'
];

$diff_files = array_filter(array_map('trim', explode("\n", $diff_out)));
$conflicting_paths = [];

foreach ($untracked_files as $untracked) {
    if ($untracked === 'uploads/') {
        // uploads/ itself is a folder. Check if any file in diff starts with uploads/ (excluding uploads/.htaccess which is tracked)
        foreach ($diff_files as $df) {
            if (strpos($df, 'uploads/') === 0 && $df !== 'uploads/.htaccess') {
                $conflicting_paths[] = "$df (incoming tracked file conflicts with untracked uploads/ directory)";
            }
        }
    } else {
        if (in_array($untracked, $diff_files, true)) {
            $conflicting_paths[] = $untracked;
        }
    }
}

if (!empty($conflicting_paths)) {
    echo "WARNING: Conflicting untracked files detected! These files are modified on remote and untracked on local production:\n";
    foreach ($conflicting_paths as $p) {
        echo "  - $p\n";
    }
    echo "FAST-FORWARD CANNOT BE COMPLETED SAFELY WITHOUT BACKING UP THESE FILES.\n";
} else {
    echo "SUCCESS: No conflicting untracked files detected. A fast-forward pull is safe.\n";
}
echo "--------------------------------------------------------\n\n";

// 3. Execute Pull if requested
$pull_executed = false;
$pull_success = false;

if (isset($_GET['pull']) && $_GET['pull'] == '1') {
    if (!empty($conflicting_paths)) {
        echo "ERROR: Pull aborted due to conflicts. Please back up conflicting files first.\n";
    } else {
        echo "--- 8. EXECUTING GIT PULL FAST-FORWARD ---\n";
        $pull_executed = true;
        $pull_out = run_cmd('git pull --ff-only origin main 2>&1');
        
        if (strpos($pull_out, 'fatal:') === false && strpos($pull_out, 'error:') === false) {
            $pull_success = true;
            echo "SUCCESS: Git pull --ff-only executed successfully.\n";
        } else {
            echo "ERROR: Git pull --ff-only failed.\n";
        }
        echo "--------------------------------------------------------\n\n";
    }
}

// 4. Verify post-pull state
if ($pull_executed && $pull_success) {
    echo "--- 9. POST-PULL VERIFICATION ---\n";
    run_cmd('git status');
    run_cmd('git log -1 --oneline --format="%H - %ad - %s"');
    
    echo "--- 10. FILE AVAILABILITY CHECK ---\n";
    $files_to_check = [
        'assessment-results.php',
        'database-update-24.sql',
        'database-update-25.sql',
        'database-update-26.sql',
        'includes/admin_nav.php',
        'includes/auth.php',
        'student-study-reports.php',
        'studyplan.php',
        'student-mentoring.php',
        'communication-dashboard.php',
        'phpinstalmentpaymentupdate.php',
        'includes/session_cron.php',
        'includes/communication/CommunicationEngine.php',
        'config/database.php'
    ];
    
    foreach ($files_to_check as $file) {
        $exists = file_exists($repo_path . '/' . $file);
        echo str_pad($file, 48) . ": " . ($exists ? "PRESENT" : "MISSING") . "\n";
    }
    echo "--------------------------------------------------------\n\n";
    
    echo "--- 11. PRODUCTION DATA PRESERVATION CHECK ---\n";
    $data_to_check = [
        'uploads/' => is_dir($repo_path . '/uploads'),
        'config/secrets.php' => file_exists($repo_path . '/config/secrets.php'),
    ];
    foreach ($data_to_check as $item => $status) {
        echo str_pad($item, 48) . ": " . ($status ? "PRESERVED" : "LOST/MISSING") . "\n";
    }
    echo "--------------------------------------------------------\n\n";
}

echo "END OF DEPLOYMENT REPORT\n";
echo "PLEASE DELETE THIS FILE IMMEDIATELY AFTER USE!\n";
