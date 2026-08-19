<?php
/**
 * PEPP ERP Audit Diagnostic Tool.
 * 
 * INSTRUCTIONS:
 * 1. Upload this file as `audit-diagnostic.php` to your production `/admissions/` directory.
 * 2. Visit: https://pepplearning.in/admissions/audit-diagnostic.php?secret=PEPP_Audit_Secret_Token_2026
 *    (Or log in as an admin first, then visit without the secret parameter).
 * 3. Copy the output of this page and share it.
 * 4. IMMEDIATELY DELETE this file from the production server after checking.
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
    echo "ERROR: Unauthorized access. Please log in as an administrator or provide the correct secret token.\n";
    exit();
}

echo "PEPP ERP DEPLOYMENT SYNCHRONIZATION DIAGNOSTIC\n";
echo "=============================================\n";
echo "Run Time : " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version : " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n\n";

// 2. File Audit (Compare MD5 hashes)
echo "PRODUCTION FILE INTEGRITY AUDIT\n";
echo "-------------------------------\n";

$expected_hashes = [
    'includes/admin_nav.php' => '663E3B766114FD3351EF69D0BBD6FA69',
    'includes/auth.php' => 'B8CD4E56D3079D03246A10A350092C80',
    'assessment-results.php' => '3B5E8F1667A563C3FB7DE3E2FD3CA054',
    'student-study-reports.php' => '3315319CD9EDCF54785DE38D2C6E6C77',
    'studyplan.php' => '435D48A1107CE827413BE7BE4F9F914E',
    'student-mentoring.php' => '700E6C24710F621D56FFC43C7EF9BEA6',
    'communication-dashboard.php' => 'D472606505BB70D0AF64F4D1141BC35C',
    'phpinstalmentpaymentupdate.php' => 'E2094CE3341E4659FF518616A8C89B0B',
    'includes/session_cron.php' => '2108BD3663A7755009E79BB5EFA3C2EC',
    'includes/communication/CommunicationEngine.php' => '34B4698EFAD973239B52AEB577FC102A',
    'config/database.php' => '2EFAFDE514312C477E8D0BAEE756F41B',
    'database-update-24.sql' => 'BC88AB27FDCDD84E96F5C3654F1B48CC',
    'database-update-26.sql' => 'E738E979039CD193CBEF3D443CE45259',
    'clear-opcache.php' => 'DC2FB85FC23F14FD80642D3277202867',
];

$out_of_sync = [];

foreach ($expected_hashes as $file => $expected_hash) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo str_pad($file, 48) . ": MISSING\n";
        $out_of_sync[] = $file;
        continue;
    }
    
    $file_content = file_get_contents($path);
    $actual_hash = strtoupper(md5($file_content));
    $mtime = date('Y-m-d H:i:s', filemtime($path));
    
    if ($actual_hash === strtoupper($expected_hash)) {
        echo str_pad($file, 48) . ": MATCH (Latest Repository Version) [mtime: $mtime]\n";
    } else {
        echo str_pad($file, 48) . ": MISMATCH (Production: $actual_hash, Expected: $expected_hash) [mtime: $mtime]\n";
        $out_of_sync[] = $file;
    }
}
echo "\n";

// 3. Database Check
echo "DATABASE SCHEMAS & MIGRATION AUDIT\n";
echo "----------------------------------\n";

if (!file_exists(__DIR__ . '/config/database.php')) {
    echo "ERROR: config/database.php not found. Cannot connect to database.\n\n";
} else {
    try {
        // Require database.php to get database configuration parameters, but try to catch self-healing exceptions
        require_once __DIR__ . '/config/database.php';
        
        if (!isset($pdo) && isset($conn)) {
            $pdo = $conn;
        }
        
        if (isset($pdo)) {
            // Helper function to check if table exists
            $table_exists = function($tableName) use ($pdo) {
                try {
                    $stmt = $pdo->query("SHOW TABLES LIKE '$tableName'");
                    return $stmt->fetchColumn() !== false;
                } catch (Exception $e) {
                    return false;
                }
            };
            
            // Helper function to check column in table
            $column_exists = function($tableName, $columnName) use ($pdo) {
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
                    return $stmt->fetchColumn() !== false;
                } catch (Exception $e) {
                    return false;
                }
            };
            
            // Helper function to check index in table
            $index_exists = function($tableName, $indexName) use ($pdo) {
                try {
                    $stmt = $pdo->prepare("SHOW INDEX FROM `$tableName` WHERE Key_name = ?");
                    $stmt->execute([$indexName]);
                    return $stmt->fetchColumn() !== false;
                } catch (Exception $e) {
                    return false;
                }
            };

            // Audit Migration 23
            echo "Migration 23 (Study Plan Analytics updates):\n";
            $m23_table = 'study_plan_analytics';
            if ($table_exists($m23_table)) {
                $cols = ['completion_status', 'cleared_by', 'cleared_at', 'clear_reason', 'active_completion_status'];
                $m23_ok = true;
                foreach ($cols as $col) {
                    $exists = $column_exists($m23_table, $col);
                    echo "  - Column `$col` in `$m23_table`: " . ($exists ? "PRESENT" : "MISSING") . "\n";
                    if (!$exists) $m23_ok = false;
                }
                $idx_exists = $index_exists($m23_table, 'uq_active_student_completion');
                echo "  - Unique Index `uq_active_student_completion`: " . ($idx_exists ? "PRESENT" : "MISSING") . "\n";
                if (!$idx_exists) $m23_ok = false;
                
                echo "  - Status: " . ($m23_ok ? "FULLY EXECUTED" : "PARTIALLY EXECUTED / NOT EXECUTED") . "\n";
            } else {
                echo "  - Table `$m23_table` does not exist.\n";
                echo "  - Status: NOT EXECUTED\n";
            }
            echo "\n";

            // Audit Migration 24
            echo "Migration 24 (Assessment Results Module):\n";
            $m24_t1 = 'assessment_result_batches';
            $m24_t2 = 'assessment_results';
            $t1_ok = $table_exists($m24_t1);
            $t2_ok = $table_exists($m24_t2);
            echo "  - Table `$m24_t1`: " . ($t1_ok ? "PRESENT" : "MISSING") . "\n";
            echo "  - Table `$m24_t2`: " . ($t2_ok ? "PRESENT" : "MISSING") . "\n";
            if ($t1_ok && $t2_ok) {
                echo "  - Status: FULLY EXECUTED\n";
            } elseif ($t1_ok || $t2_ok) {
                echo "  - Status: PARTIALLY EXECUTED\n";
            } else {
                echo "  - Status: NOT EXECUTED\n";
            }
            echo "\n";

            // Audit Migration 25 (Admin Geolocation Tracking)
            echo "Migration 25 (Admin Geolocation Tracking columns):\n";
            $m25_tables = ['admin_activity_log', 'track_records', 'whatsapp_notifications'];
            $m25_cols = ['latitude', 'longitude', 'metadata'];
            $m25_ok = true;
            foreach ($m25_tables as $tbl) {
                if ($table_exists($tbl)) {
                    $tbl_ok = true;
                    foreach ($m25_cols as $col) {
                        $exists = $column_exists($tbl, $col);
                        if (!$exists) {
                            $tbl_ok = false;
                            $m25_ok = false;
                        }
                    }
                    echo "  - Table `$tbl` columns: " . ($tbl_ok ? "ALL PRESENT" : "SOME/ALL MISSING") . "\n";
                } else {
                    echo "  - Table `$tbl`: MISSING\n";
                    $m25_ok = false;
                }
            }
            echo "  - Status: " . ($m25_ok ? "FULLY EXECUTED" : "PARTIALLY EXECUTED / NOT EXECUTED") . "\n";
            echo "\n";

            // Audit Migration 26 (Admin Presence)
            echo "Migration 26 (Admin Presence Tracking):\n";
            $m26_t = 'admin_presence';
            $t26_ok = $table_exists($m26_t);
            echo "  - Table `$m26_t`: " . ($t26_ok ? "PRESENT" : "MISSING") . "\n";
            if ($t26_ok) {
                echo "  - Status: FULLY EXECUTED\n";
            } else {
                echo "  - Status: NOT EXECUTED\n";
            }
            echo "\n";

            // Audit Legacy Assessment Batches for Cross-Course Contamination
            echo "LEGACY ASSESSMENT DATA INTEGRITY AUDIT (CROSS-COURSE CONTAMINATION)\n";
            echo "------------------------------------------------------------------\n";
            if ($table_exists('assessment_result_batches') && $table_exists('assessment_results')) {
                try {
                    $batches_stmt = $pdo->query("SELECT id, course_id, course_name, academic_year, activity_title_snapshot, status FROM assessment_result_batches WHERE status = 'published'");
                    $batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $roster_cache = [];
                    $contaminated_batches_count = 0;
                    
                    if (empty($batches)) {
                        echo "No published assessment batches found to audit.\n\n";
                    } else {
                        foreach ($batches as $b) {
                            $b_id = $b['id'];
                            $c_name = $b['course_name'];
                            $year = $b['academic_year'];
                            $title = $b['activity_title_snapshot'];
                            
                            $cache_key = strtolower(trim($c_name)) . '|||' . trim($year);
                            if (!isset($roster_cache[$cache_key])) {
                                $roster_stmt = $pdo->prepare("SELECT LOWER(TRIM(email)) FROM users WHERE status = 'approved' AND LOWER(TRIM(pepp_course)) = LOWER(TRIM(?)) AND pepp_academic_year = ? AND student_status IN ('active','completed')");
                                $roster_stmt->execute([$c_name, $year]);
                                $roster_cache[$cache_key] = $roster_stmt->fetchAll(PDO::FETCH_COLUMN);
                            }
                            
                            $roster = $roster_cache[$cache_key];
                            
                            $res_stmt = $pdo->prepare("SELECT student_email FROM assessment_results WHERE batch_id = ?");
                            $res_stmt->execute([$b_id]);
                            $results = $res_stmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            $contaminated_rows = [];
                            foreach ($results as $email) {
                                $norm_email = strtolower(trim($email));
                                if (!in_array($norm_email, $roster)) {
                                    $contaminated_rows[] = $email;
                                }
                            }
                            
                            if (!empty($contaminated_rows)) {
                                $contaminated_batches_count++;
                                echo "Batch #$b_id ('$title' for Course: '$c_name', Year: '$year'):\n";
                                echo "  - Total Stored Result Rows: " . count($results) . "\n";
                                echo "  - Contaminated Result Rows: " . count($contaminated_rows) . " (emails not in selected course roster)\n";
                                echo "  - Sample Contaminated Emails: " . implode(', ', array_slice($contaminated_rows, 0, 5)) . "\n\n";
                            }
                        }
                        
                        if ($contaminated_batches_count === 0) {
                            echo "SUCCESS: All published assessment batches match their course rosters. No contamination detected.\n\n";
                        } else {
                            echo "SUMMARY: Detected $contaminated_batches_count contaminated batch(es) with cross-course result rows.\n\n";
                        }
                    }
                } catch (Exception $e) {
                    echo "  - Error auditing assessment data: " . $e->getMessage() . "\n\n";
                }
            } else {
                echo "  - Assessment tables do not exist. Skipping data audit.\n\n";
            }
            echo "\n";
            
        } else {
            echo "ERROR: PDO Database connection object not defined after requiring config/database.php.\n\n";
        }
    } catch (Throwable $e) {
        echo "DATABASE EXCEPTION: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n\n";
    }
}

// 4. OPcache & Server Caching Audit
echo "OPCACHE & SERVER CACHE SETTINGS\n";
echo "-------------------------------\n";

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(true); // get detailed list
    if ($status) {
        echo "OPcache Enabled              : Yes\n";
        echo "OPcache Cache Full           : " . ($status['cache_full'] ? 'Yes' : 'No') . "\n";
        
        $config = opcache_get_configuration();
        if ($config && isset($config['directives'])) {
            $dir = $config['directives'];
            echo "opcache.validate_timestamps  : " . ($dir['opcache.validate_timestamps'] ? 'Yes' : 'No') . "\n";
            echo "opcache.revalidate_freq      : " . $dir['opcache.revalidate_freq'] . " seconds\n";
            echo "opcache.memory_consumption   : " . ($dir['opcache.memory_consumption'] / 1024 / 1024) . " MB\n";
        }
        
        // Check if important files are cached in OPcache and if OPcache modification time matches filemtime
        echo "OPcache Script Status:\n";
        $scripts = $status['scripts'] ?? [];
        $files_to_check = [
            'includes/admin_nav.php',
            'includes/auth.php',
            'config/database.php',
            'student-study-reports.php',
            'studyplan.php'
        ];
        
        foreach ($files_to_check as $rel_path) {
            $abs_path = __DIR__ . '/' . $rel_path;
            $real_abs = realpath($abs_path);
            
            if ($real_abs && isset($scripts[$real_abs])) {
                $cached_script = $scripts[$real_abs];
                $fs_mtime = filemtime($real_abs);
                $cached_mtime = $cached_script['timestamp'] ?? 0;
                
                echo "  - `$rel_path` is CACHED:\n";
                echo "    * Hits                     : " . ($cached_script['hits'] ?? 0) . "\n";
                echo "    * Disk Modification Time   : " . date('Y-m-d H:i:s', $fs_mtime) . "\n";
                echo "    * OPcache Cached Time      : " . date('Y-m-d H:i:s', $cached_mtime) . "\n";
                
                if ($fs_mtime !== $cached_mtime) {
                    echo "    * WARNING                  : Cached time differs from disk modification time! Serving stale code.\n";
                } else {
                    echo "    * Status                   : Synced\n";
                }
            } else {
                echo "  - `$rel_path`: NOT currently cached in OPcache.\n";
            }
        }
    } else {
        echo "OPcache Enabled              : No / Status not retrievable\n";
    }
} else {
    echo "OPcache Functionality        : NOT SUPPORTED (opcache_get_status not found)\n";
}

echo "\n=============================================\n";
echo "END OF DIAGNOSTIC REPORT\n";
echo "PLEASE DELETE THIS FILE IMMEDIATELY AFTER USE!\n";
