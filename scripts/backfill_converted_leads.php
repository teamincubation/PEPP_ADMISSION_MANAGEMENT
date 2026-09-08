<?php
/**
 * PEPP ERP - Authoritative Converted Leads Backfill & Dry-Run Script
 * 
 * Strict Conservative Evidence Hierarchy:
 * 1. Alumni Referral (ALUM% referral/coupon) -> 'alumni_referral' (IMMUTABLE)
 * 2. Explicit Historical Conversion Activity (lead_activity or admin_activity_log) -> performing admin
 * 3. Authoritative Historical Student Approver (users.approved_by or student_status_log) -> approving admin
 * 4. Assigned Admin Fallback (leads.assigned_to) -> low-confidence fallback (or auto_converted if unproven)
 * 5. Auto Converted -> 'auto_converted' (when no reliable historical attribution trace exists)
 * 
 * Usage:
 *   php scripts/backfill_converted_leads.php --dry-run          (Dry run against SQLite)
 *   php scripts/backfill_converted_leads.php --mysql --dry-run  (Dry run against Production MySQL)
 *   php scripts/backfill_converted_leads.php --mysql --execute  (Execute updates against MySQL)
 */

if (in_array('--mysql', $argv, true)) {
    putenv('PEPP_USE_MYSQL=1');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$isExecute = in_array('--execute', $argv, true);

echo "===============================================================\n";
echo "CONVERTED LEADS ATTRIBUTION BACKFILL : " . ($isExecute ? "EXECUTE MODE" : "DRY RUN MODE") . "\n";
echo "Database Driver: " . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) . "\n";
echo "===============================================================\n\n";

// Only alter table in execute mode; in dry-run mode keep 100% read-only
if ($isExecute) {
    ensure_lead_converted_by_column($pdo);
}

// Fetch all legitimate admins
$legitAdmins = [];
try {
    $stmtAdmins = $pdo->query("SELECT username, full_name FROM admins WHERE username IS NOT NULL AND TRIM(username) <> ''");
    while ($row = $stmtAdmins->fetch(PDO::FETCH_ASSOC)) {
        $legitAdmins[$row['username']] = $row['full_name'] ?: $row['username'];
    }
} catch (Exception $e) {
    echo "Warning loading admins: " . $e->getMessage() . "\n";
}
echo "Legitimate Admins Found: " . count($legitAdmins) . " (" . implode(', ', array_keys($legitAdmins)) . ")\n\n";

// Check if converted_by column exists in table
$hasConvertedByCol = false;
try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if (strtolower($col['name']) === 'converted_by') {
                $hasConvertedByCol = true;
                break;
            }
        }
    } else {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM leads LIKE 'converted_by'");
        if ($stmtCol && $stmtCol->fetch()) {
            $hasConvertedByCol = true;
        }
    }
} catch (Exception $e) {}

$convertedBySelect = $hasConvertedByCol ? "l.converted_by," : "NULL as converted_by,";

// Fetch all converted leads
$stmt = $pdo->query("
    SELECT l.id, l.whatsapp_number, l.name, l.interested_course, l.status, l.assigned_to, l.converted_user_id, l.created_by, {$convertedBySelect}
           u.user_id as u_user_id, u.referral_code, u.applied_coupon, u.approved_by, u.status as u_status
    FROM leads l
    LEFT JOIN users u ON u.user_id = l.converted_user_id
    WHERE l.status = 'converted'
    ORDER BY l.id ASC
");
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalConverted = count($leads);

echo "Total Converted Leads Found: {$totalConverted}\n\n";

// Batch preload for high performance across networks/SSH tunnels
$leadIds = array_column($leads, 'id');
$userIds = array_values(array_filter(array_unique(array_column($leads, 'converted_user_id'))));

// 1. Preload coupon redemptions with ALUM%
$alumRedemptions = [];
if (!empty($userIds)) {
    try {
        $inU = implode(',', array_fill(0, count($userIds), '?'));
        $stmtC = $pdo->prepare("SELECT user_id, coupon_code FROM coupon_redemptions WHERE user_id IN ($inU) AND UPPER(coupon_code) LIKE 'ALUM%'");
        $stmtC->execute($userIds);
        while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $alumRedemptions[$r['user_id']] = $r['coupon_code'];
        }
    } catch (Exception $e) {}
}

// 2. Preload approved ALUM students for phone matching
$alumStudentsByPhone = [];
try {
    $stmtAS = $pdo->query("
        SELECT user_id, referral_code, applied_coupon, whatsapp_number, whatsapp_country_code
        FROM users
        WHERE status = 'approved' AND (UPPER(referral_code) LIKE 'ALUM%' OR UPPER(applied_coupon) LIKE 'ALUM%')
    ");
    while ($as = $stmtAS->fetch(PDO::FETCH_ASSOC)) {
        $raw = preg_replace('/\D/', '', ($as['whatsapp_country_code'] ?? '') . ($as['whatsapp_number'] ?? ''));
        if (strlen($raw) >= 10) {
            $alumStudentsByPhone[substr($raw, -10)] = $as;
        }
        $raw2 = preg_replace('/\D/', '', $as['whatsapp_number'] ?? '');
        if (strlen($raw2) >= 10) {
            $alumStudentsByPhone[substr($raw2, -10)] = $as;
        }
    }
} catch (Exception $e) {}

// 3. Preload explicit conversion activity events from lead_activity
$convActivities = [];
if (!empty($leadIds)) {
    try {
        $inL = implode(',', array_fill(0, count($leadIds), '?'));
        $stmtAct = $pdo->prepare("
            SELECT lead_id, performed_by, remark, activity_type, performed_at
            FROM lead_activity
            WHERE lead_id IN ($inL)
              AND activity_type NOT IN ('remark', 'followup', 'details_change', 'reassigned', 'converted_by_change', 'converted_by_changed')
              AND (
                  new_status = 'converted'
                  OR activity_type IN ('conversion', 'lead_converted')
                  OR (
                      activity_type = 'status_change'
                      AND (
                          remark LIKE 'Converted%'
                          OR remark LIKE 'Marked as converted%'
                          OR remark LIKE 'Marked converted%'
                          OR remark LIKE 'Lead converted%'
                          OR remark LIKE 'Lead marked converted%'
                      )
                  )
              )
            ORDER BY performed_at ASC, id ASC
        ");
        $stmtAct->execute($leadIds);
        while ($act = $stmtAct->fetch(PDO::FETCH_ASSOC)) {
            $lid = (int)$act['lead_id'];
            if (!isset($convActivities[$lid])) {
                $convActivities[$lid] = $act;
            }
        }
    } catch (Exception $e) {}
}

// 4. Preload student_status_log approvers
$studentApprovers = [];
if (!empty($userIds)) {
    try {
        $inU = implode(',', array_fill(0, count($userIds), '?'));
        $stmtSSL = $pdo->prepare("SELECT user_id, changed_by FROM student_status_log WHERE user_id IN ($inU) AND new_status = 'approved' ORDER BY changed_at ASC, id ASC");
        $stmtSSL->execute($userIds);
        while ($ssl = $stmtSSL->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($studentApprovers[$ssl['user_id']])) {
                $studentApprovers[$ssl['user_id']] = $ssl['changed_by'];
            }
        }
    } catch (Exception $e) {}
}

$countAlumni = 0;
$countHistoricalAdmin = 0;
$countAdminBreakdown = [];
$countApprovedBy = 0;
$countApprovedByBreakdown = [];
$countAssignedFallback = 0;
$countAssignedBreakdown = [];
$countAutoConverted = 0;
$countUnresolved = 0;
$countAlreadySet = 0;
$alreadySetBreakdown = [];

$classifications = [];

foreach ($leads as $lead) {
    $leadId = (int)$lead['id'];
    $currentVal = trim((string)($lead['converted_by'] ?? ''));
    $uId = $lead['converted_user_id'];
    $normPhone = preg_replace('/\D/', '', $lead['whatsapp_number'] ?? '');
    $last10 = strlen($normPhone) >= 10 ? substr($normPhone, -10) : '';

    $evidenceSource = '';
    $finalAttribution = null;
    $classificationCategory = '';
    $reason = '';

    // Track already set values
    if ($currentVal !== '') {
        $countAlreadySet++;
        $alreadySetBreakdown[$currentVal] = ($alreadySetBreakdown[$currentVal] ?? 0) + 1;
    }

    // 1. Check Alumni Referral Evidence (Highest Priority - ALWAYS WINS)
    $isAlumni = false;
    $refCode = strtoupper(trim((string)($lead['referral_code'] ?? '')));
    $coupCode = strtoupper(trim((string)($lead['applied_coupon'] ?? '')));

    if (str_starts_with($refCode, 'ALUM')) {
        $isAlumni = true;
        $evidenceSource = "Linked Student referral_code ({$refCode})";
    } elseif (str_starts_with($coupCode, 'ALUM')) {
        $isAlumni = true;
        $evidenceSource = "Linked Student applied_coupon ({$coupCode})";
    } elseif ($uId && isset($alumRedemptions[$uId])) {
        $isAlumni = true;
        $evidenceSource = "coupon_redemptions code ({$alumRedemptions[$uId]})";
    } elseif ($last10 && isset($alumStudentsByPhone[$last10])) {
        $sm = $alumStudentsByPhone[$last10];
        $sCode = $sm['referral_code'] ?: $sm['applied_coupon'];
        $isAlumni = true;
        $evidenceSource = "Phone matched approved student #{$sm['user_id']} ({$sCode})";
    }

    if ($isAlumni) {
        $finalAttribution = 'alumni_referral';
        $classificationCategory = 'alumni';
        $reason = "Alumni referral: permanent and immutable attribution";
        $countAlumni++;
    } else {
        // 2. Explicit Conversion Activity Evidence
        // Must be the actual historical conversion event, NOT a subsequent remark or general activity.
        // Chronologically earliest conversion transition event.
        if (isset($convActivities[$leadId])) {
            $act = $convActivities[$leadId];
            $performer = trim($act['performed_by']);
            if (isset($legitAdmins[$performer])) {
                $finalAttribution = $performer;
                $classificationCategory = 'activity_admin';
                $actRem = $act['remark'] ? ": '{$act['remark']}'" : "";
                $evidenceSource = "Conversion activity ({$act['activity_type']} by {$performer}{$actRem})";
                $reason = "Admin explicitly performed the historical conversion event";
                $countHistoricalAdmin++;
                $countAdminBreakdown[$performer] = ($countAdminBreakdown[$performer] ?? 0) + 1;
            } else {
                $finalAttribution = 'unresolved';
                $classificationCategory = 'unresolved';
                $evidenceSource = "Unrecognized conversion performer: '{$performer}'";
                $reason = "Performer '{$performer}' is not in legitimate admins table";
                $countUnresolved++;
            }
        }
        // 3. Historical converted_user_id / Authoritative Student Approver Evidence
        elseif (!empty($lead['approved_by'])) {
            $approver = trim($lead['approved_by']);
            if (isset($legitAdmins[$approver])) {
                $finalAttribution = $approver;
                $classificationCategory = 'approved_by_admin';
                $evidenceSource = "Linked Student users.approved_by: {$approver}";
                $reason = "Student admission approver acted as conversion actor";
                $countApprovedBy++;
                $countApprovedByBreakdown[$approver] = ($countApprovedByBreakdown[$approver] ?? 0) + 1;
            } else {
                $finalAttribution = 'unresolved';
                $classificationCategory = 'unresolved';
                $evidenceSource = "Unrecognized approved_by: '{$approver}'";
                $reason = "Approver '{$approver}' is not in legitimate admins table";
                $countUnresolved++;
            }
        } elseif ($uId && isset($studentApprovers[$uId])) {
            $approver = trim($studentApprovers[$uId]);
            if (isset($legitAdmins[$approver])) {
                $finalAttribution = $approver;
                $classificationCategory = 'approved_by_admin';
                $evidenceSource = "Linked Student student_status_log approver: {$approver}";
                $reason = "Student admission status approver acted as conversion actor";
                $countApprovedBy++;
                $countApprovedByBreakdown[$approver] = ($countApprovedByBreakdown[$approver] ?? 0) + 1;
            } else {
                $finalAttribution = 'unresolved';
                $classificationCategory = 'unresolved';
                $evidenceSource = "Unrecognized student approver: '{$approver}'";
                $reason = "Student status log approver '{$approver}' is not in legitimate admins table";
                $countUnresolved++;
            }
        }
        // 4. Assigned Admin Fallback (Low-confidence / Strict audit check)
        else {
            $assignedTo = trim((string)($lead['assigned_to'] ?? ''));
            if (!empty($assignedTo) && $assignedTo !== '__ALL__') {
                // Check if assigned admin fallback is used or auto_converted is preferred
                $finalAttribution = isset($legitAdmins[$assignedTo]) ? $assignedTo : 'unresolved';
                $classificationCategory = 'assigned_admin';
                $evidenceSource = "Assigned Admin fallback (leads.assigned_to: {$assignedTo})";
                $reason = "Low-confidence fallback: assigned admin with no conversion activity";
                if ($finalAttribution === 'unresolved') {
                    $countUnresolved++;
                } else {
                    $countAssignedFallback++;
                    $countAssignedBreakdown[$assignedTo] = ($countAssignedBreakdown[$assignedTo] ?? 0) + 1;
                }
            } else {
                // 5. Auto Converted (No reliable historical conversion attribution exists)
                $finalAttribution = 'auto_converted';
                $classificationCategory = 'auto_converted';
                $evidenceSource = "No reliable historical conversion trace (assigned_to empty or __ALL__)";
                $reason = "Conservative audit rule: no historical admin trace";
                $countAutoConverted++;
            }
        }
    }

    $classifications[] = [
        'id' => $leadId,
        'name' => $lead['name'] ?: '-',
        'status' => $lead['status'],
        'student_id' => $lead['converted_user_id'] ?: 'None',
        'assigned_to' => $lead['assigned_to'] ?: '__ALL__',
        'current' => $currentVal,
        'new' => $finalAttribution,
        'category' => $classificationCategory,
        'source' => $evidenceSource,
        'reason' => $reason
    ];
}

$totalAttributed = $countAlumni + $countHistoricalAdmin + $countApprovedBy + $countAssignedFallback + $countAutoConverted + $countUnresolved;

echo "===============================================================\n";
echo "PRODUCTION DRY RUN CLASSIFICATION SUMMARY\n";
echo "===============================================================\n";
echo sprintf("%-45s : %d\n", "Total Converted Leads", $totalConverted);
echo sprintf("%-45s : %d\n", "1. Alumni Referral (alumni_referral)", $countAlumni);
echo sprintf("%-45s : %d\n", "2. Explicit Historical Conversion Attribution", $countHistoricalAdmin);
foreach ($countAdminBreakdown as $adm => $c) {
    $admName = $legitAdmins[$adm] ?? $adm;
    echo sprintf("   • %-40s : %d\n", "{$admName} ({$adm})", $c);
}
echo sprintf("%-45s : %d\n", "3. Historical Approved-By Attribution", $countApprovedBy);
foreach ($countApprovedByBreakdown as $adm => $c) {
    $admName = $legitAdmins[$adm] ?? $adm;
    echo sprintf("   • %-40s : %d\n", "{$admName} ({$adm})", $c);
}
echo sprintf("%-45s : %d\n", "4. Assigned Admin Fallback (Low-confidence)", $countAssignedFallback);
foreach ($countAssignedBreakdown as $adm => $c) {
    $admName = $legitAdmins[$adm] ?? $adm;
    echo sprintf("   • %-40s : %d\n", "{$admName} ({$adm})", $c);
}
echo sprintf("%-45s : %d\n", "5. Auto Converted (auto_converted)", $countAutoConverted);
echo sprintf("%-45s : %d\n", "6. Ambiguous / Unresolved", $countUnresolved);
echo "---------------------------------------------------------------\n";
echo sprintf("%-45s : %d\n", "Already Set in Database", $countAlreadySet);
if ($countAlreadySet > 0) {
    foreach ($alreadySetBreakdown as $val => $c) {
        echo sprintf("   • %-40s : %d\n", $val, $c);
    }
}
echo "---------------------------------------------------------------\n";
echo sprintf("%-45s : %d / %d (%s)\n", "Integrity Total (Sum == Total)", $totalAttributed, $totalConverted, ($totalAttributed === $totalConverted ? "PASS" : "FAIL"));
echo "===============================================================\n\n";

if ($totalConverted > 0) {
    echo "REPRESENTATIVE PRODUCTION DRY-RUN SAMPLE (25 Leads Across Categories):\n";
    echo str_repeat("=", 140) . "\n";
    echo sprintf("%-8s | %-16s | %-12s | %-48s | %-16s | %-30s\n", "Lead ID", "Student/User", "Assigned To", "Historical Evidence", "Proposed By", "Reason");
    echo str_repeat("=", 140) . "\n";
    
    // Pick representative leads: Alumni, Jihadak manual, Superadmin matched, Assigned/Auto if any
    $sample = [];
    // 1. All Alumni leads
    foreach ($classifications as $c) {
        if ($c['category'] === 'alumni') $sample[] = $c;
    }
    // 2. Sample Jihadak conversions
    $jCount = 0;
    foreach ($classifications as $c) {
        if ($c['new'] === 'jihadak' && $jCount < 8) {
            $sample[] = $c;
            $jCount++;
        }
    }
    // 3. Sample Superadmin conversions
    $sCount = 0;
    foreach ($classifications as $c) {
        if ($c['new'] === 'superadmin' && $sCount < 12) {
            $sample[] = $c;
            $sCount++;
        }
    }
    // 4. Sample any assigned or auto
    foreach ($classifications as $c) {
        if (in_array($c['category'], ['assigned_admin', 'auto_converted', 'unresolved'])) {
            $sample[] = $c;
        }
    }

    foreach ($sample as $row) {
        $truncatedSource = strlen($row['source']) > 48 ? substr($row['source'], 0, 45) . '...' : $row['source'];
        $truncatedReason = strlen($row['reason']) > 30 ? substr($row['reason'], 0, 27) . '...' : $row['reason'];
        echo sprintf("%-8d | %-16s | %-12s | %-48s | %-16s | %-30s\n",
            $row['id'],
            $row['student_id'],
            $row['assigned_to'],
            $truncatedSource,
            $row['new'],
            $truncatedReason
        );
    }
    echo str_repeat("=", 140) . "\n\n";
}

if ($isExecute && $totalConverted > 0) {
    echo "Applying updates to database...\n";
    $stmtUpd = $pdo->prepare("UPDATE leads SET converted_by = ? WHERE id = ?");
    $applied = 0;
    foreach ($classifications as $u) {
        if ($u['new'] !== 'unresolved' && $u['current'] !== $u['new']) {
            $stmtUpd->execute([$u['new'], $u['id']]);
            $applied++;
        }
    }
    echo "SUCCESS: Applied {$applied} update(s).\n";
} else {
    echo "No modifications made (DRY RUN).\n";
    echo "To execute updates, run: php scripts/backfill_converted_leads.php --mysql --execute\n";
}
