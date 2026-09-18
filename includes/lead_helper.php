<?php
/**
 * PEPP Learning - Lead Management Helper
 * Canonical helpers for lead follow-up date parsing, validation, and status rules.
 */

if (!function_exists('parse_lead_followup_date')) {
    /**
     * Canonical follow-up date parser.
     * Accepts:
     *   - Y-m-d (ISO format from HTML5 date input)
     *   - d-m-Y (Standard DD-MM-YYYY display format)
     *   - d/m/Y (Slash-delimited DD/MM/YYYY)
     *   - Y/m/d (Slash-delimited YYYY/MM/DD)
     *
     * Validates:
     *   - Calendar integrity (rejects impossible calendar dates like 31-02-2026)
     *
     * Returns:
     *   - string (canonical 'Y-m-d') if valid
     *   - null if input is blank/empty
     *   - false if input is malformed, invalid format, or impossible calendar date
     *
     * @param mixed $input
     * @return string|null|false
     */
    function parse_lead_followup_date($input) {
        if ($input === null) {
            return null;
        }
        $val = trim((string)$input);
        if ($val === '') {
            return null;
        }

        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $val);
            if ($dt && $dt->format($fmt) === $val) {
                return $dt->format('Y-m-d');
            }
        }

        return false;
    }
}
