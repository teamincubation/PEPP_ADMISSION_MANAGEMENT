<?php
/**
 * Read-only database audit to check for duplicate leads by normalized phone + course.
 */
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

function normalizeLeadPhone($phone) {
    $cleaned = preg_replace('/\D/', '', $phone);
    if (strlen($cleaned) === 11 && strpos($cleaned, '0') === 0) {
        $cleaned = substr($cleaned, 1);
    }
    if (strlen($cleaned) === 10) {
        $cleaned = '91' . $cleaned;
    }
    return $cleaned;
}

function normalizeLeadCourse($course) {
    if ($course === null) return '';
    // Normalize spaces and lowercase for comparison
    return strtolower(trim(preg_replace('/\s+/', ' ', $course)));
}

try {
    echo "========================================================\n";
    echo "            LEADS DUPLICATE DATA AUDIT REPORT\n";
    echo "========================================================\n\n";

    // 1. Fetch all leads
    $stmt = $pdo->query("SELECT id, whatsapp_number, interested_course, name, status, created_at FROM leads ORDER BY id ASC");
    $allLeads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Total Leads in database: " . count($allLeads) . "\n\n";

    // 2. Map and group leads
    $groups = [];
    foreach ($allLeads as $lead) {
        $normPhone = normalizeLeadPhone($lead['whatsapp_number']);
        $normCourse = normalizeLeadCourse($lead['interested_course']);
        
        $key = $normPhone . '||' . $normCourse;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'phone' => $normPhone,
                'orig_phones' => [],
                'course' => $lead['interested_course'],
                'norm_course' => $normCourse,
                'count' => 0,
                'records' => []
            ];
        }
        
        $groups[$key]['orig_phones'][] = $lead['whatsapp_number'];
        $groups[$key]['count']++;
        $groups[$key]['records'][] = [
            'id' => $lead['id'],
            'name' => $lead['name'],
            'status' => $lead['status'],
            'created_at' => $lead['created_at'],
            'raw_phone' => $lead['whatsapp_number']
        ];
    }

    // 3. Find and display duplicates
    $duplicateCount = 0;
    $totalDuplicateRows = 0;
    
    echo "--- DUPLICATE LEADS FOUND ---\n\n";
    echo sprintf("%-15s | %-35s | %-5s | %-35s\n", "Phone", "Course", "Count", "Lead IDs (Status)");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($groups as $key => $group) {
        if ($group['count'] > 1) {
            $duplicateCount++;
            $totalDuplicateRows += ($group['count'] - 1);
            
            $recordList = [];
            foreach ($group['records'] as $rec) {
                $recordList[] = "#" . $rec['id'] . " (" . $rec['status'] . ")";
            }
            
            echo sprintf(
                "%-15s | %-35s | %-5d | %-35s\n",
                $group['phone'],
                mb_strimwidth($group['course'], 0, 35, "..."),
                $group['count'],
                implode(", ", $recordList)
            );
        }
    }
    
    echo "\n" . str_repeat("-", 100) . "\n";
    echo "Summary:\n";
    echo " - Unique phone+course group duplicates found: {$duplicateCount}\n";
    echo " - Total redundant/duplicate rows in database: {$totalDuplicateRows}\n";
    
} catch (Exception $e) {
    echo "Error running audit: " . $e->getMessage() . "\n";
}
