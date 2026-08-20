<?php
// Mock PHP unit testing script to verify legacy template reconstruction logic and safety bounds.
// Copies the exact logic from fetch-messages.php for unit test verification.

echo "=== STARTING WHATSAPP INBOX MEDIA & TEMPLATE UNIT TESTS ===\n\n";

function unit_test_get_resolved_message_text($mockTplDb, $row) {
    $text = $row['message_text'] ?? '';
    
    // Check if it's a legacy technical parameter dump
    if (strpos($text, 'WhatsApp Template: ') === 0) {
        $rawPayload = json_decode($row['raw_payload'] ?? '', true);
        if ($rawPayload && isset($rawPayload['name']) && isset($rawPayload['parameters'])) {
            $tplName = $rawPayload['name'];
            $params = $rawPayload['parameters'];
            
            // Simulating database lookup from $mockTplDb
            $tpl = $mockTplDb[$tplName] ?? null;
            
            if ($tpl) {
                // Ensure the template has not been updated since the message was created
                $msgTime = strtotime($row['created_at']);
                $tplUpdateTime = strtotime($tpl['updated_at']);
                
                if ($tplUpdateTime <= $msgTime) {
                    $meta = json_decode($tpl['meta_data'] ?? '', true) ?: [];
                    $bodyText = $meta['body_text'] ?? '';
                    if (!empty($bodyText)) {
                        // Count expected placeholders
                        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                        $expectedParamsCount = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
                        
                        // Only reconstruct if parameters count matches expectation
                        if (count($params) >= $expectedParamsCount) {
                            $compiled = $bodyText;
                            foreach ($params as $idx => $val) {
                                $placeholder = '{{' . ($idx + 1) . '}}';
                                $compiled = str_replace($placeholder, $val, $compiled);
                            }
                            return $compiled;
                        }
                    }
                }
            }
        }
    }
    return $text;
}

// Prepare mock templates database
$mockTplDb = [
    'pepp_installment_reminder' => [
        'updated_at' => '2026-08-19 12:00:00', // Last updated August 19, 2026
        'meta_data' => json_encode([
            'body_text' => "Dear {{1}},\n\nThis is a reminder regarding your {{2}} installment of {{3}} for the {{4}} course.\n\nPayment Due Date: {{5}}."
        ])
    ]
];

// TEST A: Correct reconstruction (Message created on August 20, template last updated August 19 - tplUpdateTime <= msgTime)
$messageA = [
    'message_text' => "WhatsApp Template: pepp_installment_reminder\nParameters:\nParam 1: Ashidha ks\nParam 2: 2nd\nParam 3: 1,999\nParam 4: MSW\nParam 5: 20 Aug 2026",
    'raw_payload' => json_encode([
        'name' => 'pepp_installment_reminder',
        'parameters' => ["Ashidha ks", "2nd", "1,999", "MSW", "20 Aug 2026"]
    ]),
    'created_at' => '2026-08-20 12:00:00'
];

$resA = unit_test_get_resolved_message_text($mockTplDb, $messageA);
echo "[TEST A - Correct Resolution]\n";
echo "Input:\n" . $messageA['message_text'] . "\n\n";
echo "Output:\n" . $resA . "\n";
if (strpos($resA, "Dear Ashidha ks,") === 0) {
    echo ">> [PASS] Resolved correctly!\n\n";
} else {
    echo ">> [FAIL] Did not resolve correctly!\n\n";
}


// TEST B: Safety check - template was updated *after* message sent (tplUpdateTime > msgTime)
// e.g. message sent in January 2026, template updated in August 2026. Should NOT resolve.
$messageB = $messageA;
$messageB['created_at'] = '2026-01-01 12:00:00'; // Message created in Jan

$resB = unit_test_get_resolved_message_text($mockTplDb, $messageB);
echo "[TEST B - Safety Block on Modification]\n";
echo "Output:\n" . $resB . "\n";
if (strpos($resB, "WhatsApp Template: ") === 0) {
    echo ">> [PASS] Successfully blocked reconstruction because template was modified since sending.\n\n";
} else {
    echo ">> [FAIL] Reconstructed message from a newer template definition!\n\n";
}


// TEST C: Safety check - parameter count mismatch (too few parameters enqueued)
$messageC = $messageA;
$messageC['raw_payload'] = json_encode([
    'name' => 'pepp_installment_reminder',
    'parameters' => ["Ashidha ks", "2nd"] // only 2 parameters provided, expects 5
]);

$resC = unit_test_get_resolved_message_text($mockTplDb, $messageC);
echo "[TEST C - Parameter Count Mismatch]\n";
echo "Output:\n" . $resC . "\n";
if (strpos($resC, "WhatsApp Template: ") === 0) {
    echo ">> [PASS] Successfully blocked reconstruction due to parameter count mismatch.\n\n";
} else {
    echo ">> [FAIL] Reconstructed with insufficient parameters!\n\n";
}


// TEST D: Safety check - missing template
$messageD = $messageA;
$messageD['raw_payload'] = json_encode([
    'name' => 'non_existent_template',
    'parameters' => ["Ashidha ks"]
]);

$resD = unit_test_get_resolved_message_text($mockTplDb, $messageD);
echo "[TEST D - Missing Template Fallback]\n";
echo "Output:\n" . $resD . "\n";
if (strpos($resD, "WhatsApp Template: ") === 0) {
    echo ">> [PASS] Successfully fell back to original technical dump when template is missing.\n\n";
} else {
    echo ">> [FAIL] Did not fallback correctly.\n\n";
}

echo "=== ALL UNIT TESTS COMPLETED ===\n";
