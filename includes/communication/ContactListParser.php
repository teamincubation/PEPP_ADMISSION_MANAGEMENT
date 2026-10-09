<?php
/**
 * PEPP Learning ERP - Contact List Parser
 *
 * Secure, zero-dependency spreadsheet and CSV parser for bulk communication campaigns.
 * Handles:
 * 1. CSV (UTF-8 BOM detection, delimiter detection, quote handling, empty row skipping)
 * 2. XLSX (Native ZipArchive + SimpleXML, sharedStrings, inlineStr, sparse cell handling, formula neutrality)
 * 3. XLS (HTML-table XLS support, graceful notice for binary BIFF)
 * 4. Phone column auto-detection and ambiguity resolution
 * 5. E.164 phone normalization with configurable default country
 * 6. In-file recipient deduplication
 * 7. Sample CSV and XLSX template generators
 */

class ContactListParser {

    /** Maximum allowed upload size in bytes (10MB default) */
    const MAX_UPLOAD_SIZE = 10485760; // 10MB

    /** Supported extensions */
    const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'xls'];

    /** Supported MIME types */
    const ALLOWED_MIMES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'text/x-csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/octet-stream' // fallback for some browsers
    ];

    /** Likely phone column keywords (case-insensitive, normalized) */
    const PHONE_HEADER_KEYWORDS = [
        'phone',
        'phone number',
        'phonenumber',
        'phone no',
        'mobile',
        'mobile number',
        'mobilenumber',
        'mobile no',
        'whatsapp',
        'whatsapp number',
        'whatsappnumber',
        'whatsapp no',
        'wa phone',
        'wa number',
        'contact',
        'contact number',
        'contactnumber',
        'contact no',
        'telephone',
        'tel',
        'number'
    ];

    /** Likely country column keywords */
    const COUNTRY_HEADER_KEYWORDS = [
        'country',
        'country code',
        'countrycode',
        'phone country',
        'dial code',
        'dialcode',
        'isd code'
    ];

    /**
     * Parse uploaded file and return structured result.
     *
     * @param string $filePath Absolute path to the file
     * @param string $originalName Original file name
     * @param string $defaultCountry Default country dial code (e.g. '91')
     * @param string|null $selectedPhoneCol Optional explicitly selected phone column
     * @return array
     */
    public static function parseFile(string $filePath, string $originalName, string $defaultCountry = '91', ?string $selectedPhoneCol = null): array {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [
                'success' => false,
                'error' => 'Unable to read the uploaded file. Please ensure the file is accessible.'
            ];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return [
                'success' => false,
                'error' => 'The uploaded file is empty. Please upload a file containing contact data.'
            ];
        }

        if ($fileSize > self::MAX_UPLOAD_SIZE) {
            return [
                'success' => false,
                'error' => 'File size exceeds maximum limit of ' . (self::MAX_UPLOAD_SIZE / (1024 * 1024)) . 'MB.'
            ];
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return [
                'success' => false,
                'error' => 'Unsupported file format. Please upload an Excel (.xlsx/.xls) or CSV (.csv) file.'
            ];
        }

        // Parse raw rows based on extension
        $rawResult = null;
        if ($ext === 'csv') {
            $rawResult = self::parseCsv($filePath);
        } elseif ($ext === 'xlsx') {
            $rawResult = self::parseXlsx($filePath);
        } elseif ($ext === 'xls') {
            $rawResult = self::parseXls($filePath);
        }

        if (!$rawResult || empty($rawResult['success'])) {
            return [
                'success' => false,
                'error' => $rawResult['error'] ?? 'Unable to parse spreadsheet file. Please check file format.'
            ];
        }

        $headers = $rawResult['headers'] ?? [];
        $rows = $rawResult['rows'] ?? [];

        if (empty($headers) || empty($rows)) {
            return [
                'success' => false,
                'error' => 'No data rows found in the uploaded file.'
            ];
        }

        // Detect phone and country columns
        $phoneCandidates = self::detectPhoneColumns($headers);
        $countryCol = self::detectCountryColumn($headers);

        $chosenPhoneCol = null;
        $isAmbiguous = false;

        if ($selectedPhoneCol !== null && in_array($selectedPhoneCol, $headers, true)) {
            $chosenPhoneCol = $selectedPhoneCol;
        } elseif (count($phoneCandidates) === 1) {
            $chosenPhoneCol = $phoneCandidates[0];
        } elseif (count($phoneCandidates) > 1) {
            $isAmbiguous = true;
            // Do NOT silently choose an ambiguous column. Require user confirmation.
            $chosenPhoneCol = null;
        }

        // If phone column is resolved, process, normalize, and deduplicate rows
        $processedRows = [];
        $validRecipients = [];
        $seenPhones = [];
        $totalRows = count($rows);
        $validCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;
        $emptyCount = 0;

        if ($chosenPhoneCol !== null) {
            foreach ($rows as $idx => $row) {
                $rawPhone = isset($row[$chosenPhoneCol]) ? trim((string)$row[$chosenPhoneCol]) : '';
                $rowCountry = ($countryCol && isset($row[$countryCol])) ? trim((string)$row[$countryCol]) : null;

                $normResult = self::normalizePhone($rawPhone, $defaultCountry, $rowCountry);
                $status = $normResult['status'];
                $cleanPhone = $normResult['phone'];

                $rowItem = [
                    'row_num' => $idx + 1,
                    'raw_phone' => $rawPhone,
                    'phone' => $cleanPhone,
                    'data' => $row,
                    'status' => $status
                ];

                if ($status === 'EMPTY') {
                    $emptyCount++;
                    $rowItem['status'] = 'EMPTY';
                } elseif ($status === 'INVALID') {
                    $invalidCount++;
                    $rowItem['status'] = 'INVALID';
                } elseif ($status === 'VALID') {
                    if (isset($seenPhones[$cleanPhone])) {
                        $duplicateCount++;
                        $rowItem['status'] = 'DUPLICATE';
                    } else {
                        $seenPhones[$cleanPhone] = true;
                        $validCount++;
                        $rowItem['status'] = 'READY';

                        // Extract name candidate if present
                        $recName = self::extractNameFromRow($row);

                        $validRecipients[] = [
                            'row_num' => $idx + 1,
                            'phone' => $cleanPhone,
                            'name' => $recName,
                            'data' => $row
                        ];
                    }
                }

                $processedRows[] = $rowItem;
            }
        }

        return [
            'success' => true,
            'file_name' => $originalName,
            'headers' => $headers,
            'phone_candidates' => $phoneCandidates,
            'is_ambiguous' => $isAmbiguous,
            'detected_phone_col' => $chosenPhoneCol,
            'detected_country_col' => $countryCol,
            'default_country' => $defaultCountry,
            'total_rows' => $totalRows,
            'valid_count' => $validCount,
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'empty_count' => $emptyCount,
            'final_recipients_count' => $validCount,
            'preview_rows' => $processedRows,
            'valid_recipients' => $validRecipients
        ];
    }

    /**
     * Detect likely phone column candidates from headers.
     *
     * @param array $headers
     * @return array List of matching header names
     */
    public static function detectPhoneColumns(array $headers): array {
        $candidates = [];
        foreach ($headers as $h) {
            $norm = strtolower(trim(preg_replace('/[\s_-]+/', ' ', (string)$h)));
            if (in_array($norm, self::PHONE_HEADER_KEYWORDS, true)) {
                $candidates[] = $h;
                continue;
            }
            // Check substring keywords
            foreach (['whatsapp', 'mobile', 'phone', 'contact'] as $kw) {
                if (strpos($norm, $kw) !== false) {
                    $candidates[] = $h;
                    break;
                }
            }
        }
        return array_values(array_unique($candidates));
    }

    /**
     * Detect likely country column from headers.
     *
     * @param array $headers
     * @return string|null
     */
    public static function detectCountryColumn(array $headers): ?string {
        foreach ($headers as $h) {
            $norm = strtolower(trim(preg_replace('/[\s_-]+/', ' ', (string)$h)));
            if (in_array($norm, self::COUNTRY_HEADER_KEYWORDS, true)) {
                return $h;
            }
            if (strpos($norm, 'country') !== false || strpos($norm, 'dial code') !== false) {
                return $h;
            }
        }
        return null;
    }

    /**
     * Extract a likely contact name from a row.
     *
     * @param array $row
     * @return string
     */
    public static function extractNameFromRow(array $row): string {
        foreach ($row as $k => $v) {
            $normK = strtolower(trim(preg_replace('/[\s_-]+/', ' ', (string)$k)));
            if (in_array($normK, ['name', 'full name', 'student name', 'contact name', 'lead name', 'first name'], true)) {
                $val = trim((string)$v);
                if ($val !== '') return $val;
            }
        }
        foreach ($row as $k => $v) {
            $normK = strtolower(trim((string)$k));
            if (strpos($normK, 'name') !== false) {
                $val = trim((string)$v);
                if ($val !== '') return $val;
            }
        }
        return 'Contact';
    }

    /**
     * Normalize a phone number to standard international format (digits only).
     *
     * Rules:
     * - Remove spaces, dashes, parentheses, dots, slashes, and non-digits.
     * - Detect leading '+' or '00' international prefix.
     * - If local number without country code, prepend default country code (e.g. 91).
     * - Validate digit length (typically 10-15 digits for E.164 without plus).
     * - Reject invalid or non-numeric strings.
     *
     * @param string $rawPhone
     * @param string $defaultCountry (digits, e.g. '91')
     * @param string|null $rowCountry
     * @return array ['status' => 'VALID'|'INVALID'|'EMPTY', 'phone' => string]
     */
    public static function normalizePhone(string $rawPhone, string $defaultCountry = '91', ?string $rowCountry = null): array {
        $raw = trim($rawPhone);
        if ($raw === '') {
            return ['status' => 'EMPTY', 'phone' => ''];
        }

        // Clean default country code
        $defCountry = preg_replace('/\D/', '', $defaultCountry) ?: '91';

        // Check if raw string had leading '+' or '00'
        $hasLeadingPlus = (strpos($raw, '+') === 0);
        $hasLeading00 = (strpos($raw, '00') === 0);

        // Strip non-digit characters
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return ['status' => 'INVALID', 'phone' => ''];
        }

        // If had leading 00, strip 00 from start
        if ($hasLeading00 && strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        // Row-level country override if valid
        $rowCountryDigits = $rowCountry ? preg_replace('/\D/', '', $rowCountry) : '';

        $normalized = '';

        if ($hasLeadingPlus || $hasLeading00) {
            // Explicit international format provided: preserve digits
            $normalized = $digits;
        } elseif (!empty($rowCountryDigits)) {
            // Row-level country column supplied
            if (strpos($digits, '0') === 0 && strlen($digits) > 8) {
                $digits = substr($digits, 1);
            }
            if (strpos($digits, $rowCountryDigits) === 0 && strlen($digits) >= strlen($rowCountryDigits) + 7) {
                $normalized = $digits;
            } elseif (strlen($digits) >= 7 && strlen($digits) <= 11) {
                $normalized = $rowCountryDigits . $digits;
            } else {
                $normalized = $digits;
            }
        } else {
            // Local number or international number without '+'
            if ($defCountry === '91') {
                // India specific normalization
                if (strlen($digits) === 10) {
                    // Standard 10-digit Indian mobile (e.g. 9876543210 -> 919876543210)
                    $normalized = '91' . $digits;
                } elseif (strlen($digits) === 11 && strpos($digits, '0') === 0) {
                    // 11 digits starting with 0 (e.g. 09876543210 -> 919876543210)
                    $normalized = '91' . substr($digits, 1);
                } elseif (strlen($digits) === 12 && strpos($digits, '91') === 0) {
                    // Already 12 digits starting with 91
                    $normalized = $digits;
                } elseif (strlen($digits) >= 10 && strlen($digits) <= 15) {
                    // Other valid international length without plus
                    $normalized = $digits;
                } else {
                    $normalized = $digits;
                }
            } else {
                // General default country logic (e.g. UAE 971, Saudi 966, UK 44, US 1)
                if (strpos($digits, '0') === 0 && strlen($digits) > 8) {
                    $digits = substr($digits, 1);
                }
                if (strpos($digits, $defCountry) === 0 && strlen($digits) >= strlen($defCountry) + 7) {
                    $normalized = $digits;
                } elseif (strlen($digits) >= 7 && strlen($digits) <= 11) {
                    $normalized = $defCountry . $digits;
                } else {
                    $normalized = $digits;
                }
            }
        }

        // Validation checks
        $len = strlen($normalized);
        if ($len < 10 || $len > 15) {
            return ['status' => 'INVALID', 'phone' => $normalized];
        }

        // Reject all-identical dummy digits (e.g. 0000000000, 1111111111, 9999999999)
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return ['status' => 'INVALID', 'phone' => $normalized];
        }

        // Specific India validation (12 digits, mobile starting with 5-9)
        if (strpos($normalized, '91') === 0) {
            if (strlen($normalized) !== 12) {
                return ['status' => 'INVALID', 'phone' => $normalized];
            }
            $mobilePart = substr($normalized, 2);
            if (!preg_match('/^[5-9]\d{9}$/', $mobilePart)) {
                return ['status' => 'INVALID', 'phone' => $normalized];
            }
        }

        // Specific Saudi Arabia validation (966 followed by 5 and 8 digits = 12 digits)
        if (strpos($normalized, '966') === 0) {
            if (strlen($normalized) !== 12 || !preg_match('/^9665\d{8}$/', $normalized)) {
                return ['status' => 'INVALID', 'phone' => $normalized];
            }
        }

        // Specific UAE validation (971 followed by 5 and 8 digits = 12 digits)
        if (strpos($normalized, '971') === 0) {
            if (strlen($normalized) !== 12 || !preg_match('/^9715\d{8}$/', $normalized)) {
                return ['status' => 'INVALID', 'phone' => $normalized];
            }
        }

        return ['status' => 'VALID', 'phone' => $normalized];
    }

    /**
     * Alias method for normalizePhone providing both digits and E.164 (+prefix) format.
     *
     * @param string $rawPhone
     * @param string $defaultCountry
     * @param string|null $rowCountry
     * @return array ['status' => string, 'phone' => string, 'normalized' => string, 'is_valid' => bool]
     */
    public static function normalizePhoneNumber(string $rawPhone, string $defaultCountry = '91', ?string $rowCountry = null): array {
        $res = self::normalizePhone($rawPhone, $defaultCountry, $rowCountry);
        $res['is_valid'] = ($res['status'] === 'VALID');
        $res['normalized'] = (!empty($res['phone']) && $res['is_valid']) ? '+' . $res['phone'] : $res['phone'];
        return $res;
    }

    /**
     * Parse CSV file with automatic BOM stripping, delimiter detection, and sanitization.
     *
     * @param string $filePath
     * @return array
     */
    public static function parseCsv(string $filePath): array {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Unable to open CSV file for reading.'];
        }

        // Read first chunk to detect BOM and delimiter
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['success' => false, 'error' => 'CSV file appears to be completely empty.'];
        }

        // Check if BOM was present
        $hasBom = (substr($firstLine, 0, 3) === "\xEF\xBB\xBF");

        // Detect delimiter (comma, semicolon, tab)
        $delimiters = [',', ';', "\t"];
        $bestDelim = ',';
        $maxCount = 0;
        foreach ($delimiters as $d) {
            $cnt = substr_count($firstLine, $d);
            if ($cnt > $maxCount) {
                $maxCount = $cnt;
                $bestDelim = $d;
            }
        }

        // Rewind to parse completely
        rewind($handle);
        if ($hasBom) {
            fseek($handle, 3);
        }

        $headers = [];
        $rows = [];
        $isFirst = true;

        while (($data = fgetcsv($handle, 0, $bestDelim, '"', '\\')) !== false) {
            if ($isFirst) {
                // Clean headers
                foreach ($data as $h) {
                    $clean = trim((string)$h);
                    // Strip BOM and non-printable chars from header
                    $clean = preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/u', '', $clean);
                    $clean = trim($clean, "\"' \t\n\r\0\x0B");
                    $headers[] = $clean;
                }
                $isFirst = false;
                continue;
            }

            // Skip empty rows
            $hasData = false;
            $rowObj = [];
            foreach ($headers as $colIdx => $headerName) {
                if (empty($headerName)) {
                    $headerName = 'Col_' . ($colIdx + 1);
                }
                $val = isset($data[$colIdx]) ? trim((string)$data[$colIdx]) : '';
                // Formula neutrality: never execute formulas, preserve plain text value
                if ($val !== '') {
                    $hasData = true;
                }
                $rowObj[$headerName] = $val;
            }

            if ($hasData) {
                $rows[] = $rowObj;
            }
        }

        fclose($handle);

        return [
            'success' => true,
            'headers' => $headers,
            'rows' => $rows
        ];
    }

    /**
     * Parse XLSX file using native PHP ZipArchive and SimpleXML.
     *
     * @param string $filePath
     * @return array
     */
    public static function parseXlsx(string $filePath): array {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'PHP ZipArchive extension is required to read Excel .xlsx files.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['success' => false, 'error' => 'Unable to read this Excel file. Please verify that the file is not corrupted.'];
        }

        // 1. Read shared strings if present
        $sharedStrings = [];
        $ssXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXmlContent !== false) {
            $ssXml = @simplexml_load_string($ssXmlContent);
            if ($ssXml && isset($ssXml->si)) {
                foreach ($ssXml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        // Rich text run
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string)$r->t;
                        }
                        $sharedStrings[] = $text;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Read first worksheet
        $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXmlContent === false) {
            // Try detecting sheets from workbook.xml
            $wbXmlContent = $zip->getFromName('xl/workbook.xml');
            if ($wbXmlContent !== false) {
                $wbXml = @simplexml_load_string($wbXmlContent);
                if ($wbXml && isset($wbXml->sheets->sheet[0])) {
                    $rId = (string)($wbXml->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? 'rId1');
                    // Find target in _rels
                    $relsXmlContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
                    if ($relsXmlContent !== false) {
                        $relsXml = @simplexml_load_string($relsXmlContent);
                        if ($relsXml) {
                            foreach ($relsXml->Relationship as $rel) {
                                if ((string)$rel['Id'] === $rId) {
                                    $target = (string)$rel['Target'];
                                    $sheetXmlContent = $zip->getFromName('xl/' . ltrim($target, '/'));
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($sheetXmlContent === false) {
            $zip->close();
            return ['success' => false, 'error' => 'Could not find a valid worksheet in the Excel workbook.'];
        }

        $sheetXml = @simplexml_load_string($sheetXmlContent);
        $zip->close();

        if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
            return ['success' => false, 'error' => 'Worksheet in Excel file contains no readable row data.'];
        }

        $headers = [];
        $rows = [];
        $isFirst = true;

        foreach ($sheetXml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $cellRef = (string)$c['r']; // e.g. A1, B2
                $colIndex = self::colIndexFromCellRef($cellRef);
                $type = (string)($c['t'] ?? '');
                $val = '';

                if ($type === 's') {
                    // Shared string index
                    $ssIdx = (int)$c->v;
                    $val = $sharedStrings[$ssIdx] ?? '';
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                } elseif ($type === 'b') {
                    $val = ((string)$c->v === '1') ? 'TRUE' : 'FALSE';
                } else {
                    // Numeric, date, or formula result. Note: never evaluate formulas, only read cached result
                    $val = isset($c->v) ? (string)$c->v : '';
                }

                $cells[$colIndex] = trim($val);
            }

            if (empty($cells)) {
                continue;
            }

            // Fill sparse cell gaps up to max column
            $maxCol = max(array_keys($cells));
            $rowValues = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $rowValues[$i] = $cells[$i] ?? '';
            }

            if ($isFirst) {
                foreach ($rowValues as $i => $h) {
                    $hName = trim((string)$h);
                    $hName = preg_replace('/[\x00-\x1F\x7F]/u', '', $hName);
                    if ($hName === '') {
                        $hName = 'Col_' . ($i + 1);
                    }
                    $headers[$i] = $hName;
                }
                $isFirst = false;
                continue;
            }

            // Data row
            $hasData = false;
            $rowObj = [];
            foreach ($headers as $colIdx => $hName) {
                $cellVal = $rowValues[$colIdx] ?? '';
                if ($cellVal !== '') {
                    $hasData = true;
                }
                $rowObj[$hName] = $cellVal;
            }

            if ($hasData) {
                $rows[] = $rowObj;
            }
        }

        return [
            'success' => true,
            'headers' => array_values($headers),
            'rows' => $rows
        ];
    }

    /**
     * Convert cell coordinate (e.g. 'A1', 'BC12') to 0-based column index.
     *
     * @param string $cellRef
     * @return int
     */
    private static function colIndexFromCellRef(string $cellRef): int {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
        $len = strlen($letters);
        $col = 0;
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $col - 1);
    }

    /**
     * Parse legacy XLS file.
     * Supports HTML-based XLS exports (common in web applications).
     * For binary BIFF, provides clean actionable instruction.
     *
     * @param string $filePath
     * @return array
     */
    public static function parseXls(string $filePath): array {
        $content = file_get_contents($filePath, false, null, 0, 4096);
        if ($content === false) {
            return ['success' => false, 'error' => 'Unable to read .xls file.'];
        }

        // Check if it's an HTML-based XLS table
        if (stripos($content, '<table') !== false || stripos($content, '<html') !== false) {
            $fullContent = file_get_contents($filePath);
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $fullContent);
            $tables = $dom->getElementsByTagName('table');
            if ($tables->length === 0) {
                return ['success' => false, 'error' => 'No table found in the uploaded HTML/XLS file.'];
            }

            $table = $tables->item(0);
            $trList = $table->getElementsByTagName('tr');
            $headers = [];
            $rows = [];
            $isFirst = true;

            foreach ($trList as $tr) {
                $thList = $tr->getElementsByTagName('th');
                $tdList = $tr->getElementsByTagName('td');

                if ($isFirst && $thList->length > 0) {
                    foreach ($thList as $th) {
                        $headers[] = trim($th->textContent);
                    }
                    $isFirst = false;
                    continue;
                }

                $cells = [];
                $nodeList = ($tdList->length > 0) ? $tdList : $thList;
                foreach ($nodeList as $node) {
                    $cells[] = trim($node->textContent);
                }

                if ($isFirst) {
                    $headers = $cells;
                    $isFirst = false;
                    continue;
                }

                if (empty($cells) || implode('', $cells) === '') {
                    continue;
                }

                $rowObj = [];
                foreach ($headers as $idx => $hName) {
                    $rowObj[$hName] = $cells[$idx] ?? '';
                }
                $rows[] = $rowObj;
            }

            return [
                'success' => true,
                'headers' => $headers,
                'rows' => $rows
            ];
        }

        // Binary BIFF file notice
        return [
            'success' => false,
            'error' => 'Legacy binary Excel (.xls) file detected. For reliable import, please open the file in Excel and save it as Excel (.xlsx) or CSV (.csv).'
        ];
    }

    /**
     * Generate sample CSV template content.
     *
     * @return string
     */
    public static function generateSampleCsv(): string {
        $out = fopen('php://temp', 'r+');
        // UTF-8 BOM
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['WhatsApp Number', 'Name', 'Course', 'Batch']);
        fputcsv($out, ['9876543210', 'Rahul', 'M.Phil Psychology', '2026']);
        fputcsv($out, ['7994304400', 'Anu', 'NET Psychology', '2026']);
        fputcsv($out, ['+91 99955 12345', 'Kavya', 'CUET PG Psychology', '2026']);

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Generate sample XLSX template file.
     *
     * @param string $savePath Target path to write XLSX
     * @return bool
     */
    public static function generateSampleXlsx(string $savePath): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($savePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        // [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedString+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Contacts" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // Strings
        $strings = [
            'WhatsApp Number', 'Name', 'Course', 'Batch',
            '9876543210', 'Rahul', 'M.Phil Psychology', '2026',
            '7994304400', 'Anu', 'NET Psychology', '2026',
            '+91 99955 12345', 'Kavya', 'CUET PG Psychology', '2026'
        ];

        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">' . "\n";
        foreach ($strings as $s) {
            $ssXml .= '    <si><t>' . htmlspecialchars($s, ENT_XML1, 'UTF-8') . '</t></si>' . "\n";
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        // xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        <row r="1">
            <c r="A1" t="s"><v>0</v></c>
            <c r="B1" t="s"><v>1</v></c>
            <c r="C1" t="s"><v>2</v></c>
            <c r="D1" t="s"><v>3</v></c>
        </row>
        <row r="2">
            <c r="A2" t="s"><v>4</v></c>
            <c r="B2" t="s"><v>5</v></c>
            <c r="C2" t="s"><v>6</v></c>
            <c r="D2" t="s"><v>7</v></c>
        </row>
        <row r="3">
            <c r="A3" t="s"><v>8</v></c>
            <c r="B3" t="s"><v>9</v></c>
            <c r="C3" t="s"><v>10</v></c>
            <c r="D3" t="s"><v>11</v></c>
        </row>
        <row r="4">
            <c r="A4" t="s"><v>12</v></c>
            <c r="B4" t="s"><v>13</v></c>
            <c r="C4" t="s"><v>14</v></c>
            <c r="D4" t="s"><v>15</v></c>
        </row>
    </sheetData>
</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        return $zip->close();
    }
}
