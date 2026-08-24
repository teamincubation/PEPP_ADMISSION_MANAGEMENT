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

    public static function getERPVariables() {
        return [
            'student_name' => [
                'label' => 'Student Name',
                'description' => 'Name of the student',
                'sample' => 'John Doe'
            ],
            'student_id' => [
                'label' => 'Student ID',
                'description' => 'Unique ERP roll number or student ID',
                'sample' => 'PEPP20268575'
            ],
            'whatsapp_number' => [
                'label' => 'WhatsApp Number',
                'description' => 'Registered mobile country code and number',
                'sample' => '919876543210'
            ],
            'student_email' => [
                'label' => 'Student Email',
                'description' => 'Email of the student',
                'sample' => 'student@pepplearning.in'
            ],
            'current_course_name' => [
                'label' => 'Current Course Name',
                'description' => 'Name of the current enrolled course',
                'sample' => 'M. Clin. Psy. (Standard Plan)'
            ],
            'previous_course_name' => [
                'label' => 'Previous Course Name',
                'description' => 'Course name before migration',
                'sample' => 'M. Clin. Psy. (Basic Plan)'
            ],
            'new_course_name' => [
                'label' => 'New Course Name',
                'description' => 'Course name after migration',
                'sample' => 'M. Clin. Psy. (Standard Plan)'
            ],
            'current_course_fee' => [
                'label' => 'Current Course Fee',
                'description' => 'Authoritative fee of the current course',
                'sample' => '₹9,499'
            ],
            'previous_course_fee' => [
                'label' => 'Previous Course Fee',
                'description' => 'Original course fee before migration',
                'sample' => '₹5,999'
            ],
            'new_course_fee' => [
                'label' => 'New Course Fee',
                'description' => 'Target course catalog fee',
                'sample' => '₹9,499'
            ],
            'migration_amount_paid' => [
                'label' => 'Migration Amount Paid',
                'description' => 'Amount collected during migration/upgrade',
                'sample' => '₹3,500'
            ],
            'upgrade_amount' => [
                'label' => 'Upgrade Amount',
                'description' => 'Net cost of the migration upgrade',
                'sample' => '₹3,500'
            ],
            'outstanding_balance' => [
                'label' => 'Outstanding Balance',
                'description' => 'Remaining amount payable before migration',
                'sample' => '₹0'
            ],
            'new_outstanding_balance' => [
                'label' => 'New Outstanding Balance',
                'description' => 'Remaining amount payable after migration',
                'sample' => '₹4,499'
            ],
            'migration_date' => [
                'label' => 'Migration Date',
                'description' => 'Date on which migration was completed',
                'sample' => '25 Aug 2026'
            ],
            'migration_reason' => [
                'label' => 'Migration Reason',
                'description' => 'Description of migration justification',
                'sample' => 'Upgrade to standard course plan'
            ],
            'total_paid' => [
                'label' => 'Total Paid',
                'description' => 'Cumulative amount paid by student',
                'sample' => '₹5,999'
            ],
            'registration_fee_paid' => [
                'label' => 'Registration Fee Paid',
                'description' => 'Registration/onboarding payment amount',
                'sample' => '₹2,500'
            ],
            'installment_paid' => [
                'label' => 'Installment Paid',
                'description' => 'Sum of approved installment payments',
                'sample' => '₹3,499'
            ],
            'payment_plan' => [
                'label' => 'Payment Plan',
                'description' => 'Updated installment/payment plan',
                'sample' => 'Installment Plan (2 Parts)'
            ],
            'number_of_installments' => [
                'label' => 'Number of Installments',
                'description' => 'Installments count scheduled for user',
                'sample' => '2'
            ],
            'academic_year' => [
                'label' => 'Academic Year',
                'description' => 'Current academic year',
                'sample' => '2026-27'
            ],
            'previous_academic_year' => [
                'label' => 'Previous Academic Year',
                'description' => 'Academic year before migration',
                'sample' => '2026-27'
            ],
            'new_academic_year' => [
                'label' => 'New Academic Year',
                'description' => 'Academic year after migration',
                'sample' => '2026-27'
            ],
            'updated_payment_details' => [
                'label' => 'Updated Course / Payment Details',
                'description' => 'Human-readable summary of the new payment arrangement',
                'sample' => '2 installments of ₹2,499 each, starting 24 Sep 2026'
            ]
        ];
    }
}
