<?php
/**
 * Helper utilities for Meta API error classification and communication status mappings.
 */
class CommunicationHelper {
    /**
     * Determines whether a Meta API error code and/or message indicates a permanent
     * (non-retryable) failure.
     *
     * @param int|string|null $errorCode
     * @param string|null $errorMessage
     * @return bool
     */
    public static function isPermanentMetaFailure($errorCode, $errorMessage) {
        $code = ($errorCode !== null) ? (int)$errorCode : 0;
        $lowerMsg = ($errorMessage !== null) ? strtolower($errorMessage) : '';

        // Explicitly transient error codes from Meta:
        // 131021: Rate limit reached
        // 131048: Spammer protection rate limit
        // 429: Too Many Requests (Transient HTTP status)
        if (in_array($code, [131021, 131048, 429], true)) {
            return false;
        }

        // Explicitly permanent/non-retryable error codes:
        // 131026: Message undeliverable (user block, non-WhatsApp number)
        // 131053: Ecosystem engagement / Policy block
        // 131047: Outside 24h window (cannot send free-form message)
        // 131045: Business account locked/suspended
        // 131051: Template is paused
        // 131052: Template is disabled
        // 100: Invalid parameters, template mismatch, invalid phone, etc.
        // 190: Invalid oauth token
        if (in_array($code, [131026, 131053, 131047, 131045, 131051, 131052, 100, 190], true)) {
            return true;
        }

        // Text-based fallback checks (if code is missing or generic)
        if (
            strpos($lowerMsg, 'healthy ecosystem engagement') !== false ||
            strpos($lowerMsg, '131026') !== false ||
            strpos($lowerMsg, 'policy') !== false ||
            strpos($lowerMsg, 'not in allowed list') !== false ||
            strpos($lowerMsg, 'invalid phone number') !== false ||
            strpos($lowerMsg, 'does not exist') !== false ||
            strpos($lowerMsg, 'recipient') !== false ||
            strpos($lowerMsg, 'undeliverable') !== false ||
            strpos($lowerMsg, 'not a whatsapp number') !== false ||
            strpos($lowerMsg, 'parameter count mismatch') !== false ||
            strpos($lowerMsg, 'not approved') !== false ||
            strpos($lowerMsg, 'not found in database') !== false ||
            (strpos($lowerMsg, 'http 400') !== false && strpos($lowerMsg, 'rate limit') === false) ||
            strpos($lowerMsg, 'param') !== false ||
            strpos($lowerMsg, 'template') !== false
        ) {
            return true;
        }

        // Default: if it's a general network/curl error or generic HTTP error, we assume it's transient/retryable.
        if (
            strpos($lowerMsg, 'curl error') !== false ||
            strpos($lowerMsg, 'timeout') !== false ||
            strpos($lowerMsg, 'http 500') !== false ||
            strpos($lowerMsg, 'http 502') !== false ||
            strpos($lowerMsg, 'http 503') !== false ||
            strpos($lowerMsg, 'http 504') !== false ||
            strpos($lowerMsg, 'rate limit') !== false
        ) {
            return false;
        }

        // Default to transient for undefined failures to preserve the retry mechanism
        return false;
    }
}
