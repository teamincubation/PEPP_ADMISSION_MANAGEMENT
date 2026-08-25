<?php
/**
 * Regression Test Suite for WhatsApp Failure Classification & Retry Loop Prevention.
 */

require_once dirname(__DIR__) . '/includes/communication/CommunicationHelper.php';

function assert_test($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

echo "=== Running WhatsApp Failure Classification Unit Tests ===\n";

// Test Permanent Error Codes
assert_test("131026 is permanent", CommunicationHelper::isPermanentMetaFailure(131026, "Message undeliverable"));
assert_test("131053 is permanent", CommunicationHelper::isPermanentMetaFailure(131053, "Ecosystem block"));
assert_test("131047 is permanent", CommunicationHelper::isPermanentMetaFailure(131047, "Outside 24h window"));
assert_test("131045 is permanent", CommunicationHelper::isPermanentMetaFailure(131045, "Business account locked"));
assert_test("131051 is permanent", CommunicationHelper::isPermanentMetaFailure(131051, "Template paused"));
assert_test("131052 is permanent", CommunicationHelper::isPermanentMetaFailure(131052, "Template disabled"));
assert_test("100 is permanent", CommunicationHelper::isPermanentMetaFailure(100, "Invalid parameters"));
assert_test("190 is permanent", CommunicationHelper::isPermanentMetaFailure(190, "Invalid oauth token"));

// Test Transient Error Codes
assert_test("131021 is transient", !CommunicationHelper::isPermanentMetaFailure(131021, "Rate limit reached"));
assert_test("131048 is transient", !CommunicationHelper::isPermanentMetaFailure(131048, "Spammer protection"));
assert_test("429 is transient", !CommunicationHelper::isPermanentMetaFailure(429, "Too many requests"));

// Test Message-Based Fallbacks (code is null)
assert_test("Text block policy is permanent", CommunicationHelper::isPermanentMetaFailure(null, "This message was not delivered to maintain healthy ecosystem engagement."));
assert_test("Text invalid phone is permanent", CommunicationHelper::isPermanentMetaFailure(null, "invalid phone number"));
assert_test("Text parameter mismatch is permanent", CommunicationHelper::isPermanentMetaFailure(null, "parameter count mismatch"));
assert_test("Text not approved is permanent", CommunicationHelper::isPermanentMetaFailure(null, "template not approved"));
assert_test("Text curl timeout is transient", !CommunicationHelper::isPermanentMetaFailure(null, "CURL Error: Connection timed out"));
assert_test("Text server 502 is transient", !CommunicationHelper::isPermanentMetaFailure(null, "HTTP 502: Bad Gateway"));

echo "=== All Failure Classification Tests Passed! ===\n";
