<?php
/**
 * PEPP Learning ERP — Lecture Video Quality Assessment Helper & Parser
 *
 * Core service layer providing:
 * 1. Permanent System Work Mode helpers and authorization guards.
 * 2. Dependency-light XLSX & CSV parsers (supporting both standardized 15-column
 *    and legacy 2-level reference formats).
 * 3. Comprehensive validation engine (file, required columns, rows, grades, remarks,
 *    normalized language, duplicate lecture detection, consistency checks).
 * 4. Standard machine-readable template generators (.xlsx and .csv).
 * 5. Transactional storage and synchronization with ld_tasks / ld_task_topics.
 */

declare(strict_types=1);

require_once __DIR__ . '/ai/QualityAssessmentAiService.php';

const LD_QA_SYSTEM_KEY = 'lecture_quality_assessment';
const LD_QA_MODE_NAME = 'Lectures: Quality Assessment';
const LD_QA_QUANTITY_LABEL = 'Hour';

/**
 * Checks if a work mode record or mode name represents the permanent Quality Assessment system mode.
 */
function is_ld_quality_assessment_mode($mode): bool {
    if (is_array($mode)) {
        if (!empty($mode['mode_key']) && $mode['mode_key'] === LD_QA_SYSTEM_KEY) {
            return true;
        }
        if (!empty($mode['is_system'])) {
            return true;
        }
        if (!empty($mode['mode_name']) && trim($mode['mode_name']) === LD_QA_MODE_NAME) {
            return true;
        }
        return false;
    }
    if (is_string($mode)) {
        $trimmed = trim($mode);
        return $trimmed === LD_QA_SYSTEM_KEY || $trimmed === LD_QA_MODE_NAME;
    }
    return false;
}

/**
 * Retrieves the permanent Quality Assessment mode from ld_work_modes.
 */
function get_ld_quality_assessment_mode(PDO $pdo): ?array {
    try {
        // First try by mode_key if column exists
        $hasKeyCol = false;
        try {
            $pdo->query("SELECT mode_key FROM ld_work_modes LIMIT 1");
            $hasKeyCol = true;
        } catch (Exception $e) {}

        if ($hasKeyCol) {
            $stmt = $pdo->prepare("SELECT * FROM ld_work_modes WHERE mode_key = ? LIMIT 1");
            $stmt->execute([LD_QA_SYSTEM_KEY]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        // Fallback by mode_name
        $stmt = $pdo->prepare("SELECT * FROM ld_work_modes WHERE mode_name = ? LIMIT 1");
        $stmt->execute([LD_QA_MODE_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        error_log("get_ld_quality_assessment_mode error: " . $e->getMessage());
        return null;
    }
}

/**
 * Normalizes text for internal consistency and duplicate detection:
 * trims, lowercases, and collapses internal consecutive whitespace.
 */
function normalize_ld_qa_text(string $text): string {
    $text = trim($text);
    $text = mb_strtolower($text, 'UTF-8');
    return preg_replace('/\s+/', ' ', $text);
}

/**
 * Normalizes language string to standard 'ML' or 'EN', or null if invalid.
 */
function normalize_ld_qa_language(string $lang): ?string {
    $norm = strtoupper(trim($lang));
    if ($norm === 'MALAYALAM' || $norm === 'ML') return 'ML';
    if ($norm === 'ENGLISH' || $norm === 'EN') return 'EN';
    return null;
}

/**
 * Generates the standardized CSV template.
 */
function generate_ld_qa_csv_template(): string {
    $output = fopen('php://memory', 'r+');
    // UTF-8 BOM
    fwrite($output, "\xEF\xBB\xBF");

    // Standard 15 Columns
    $headers = [
        'Sl. No.',
        'Chapter',
        'Lecture Title',
        'Language (ML/EN)',
        'Faculty Name',
        'Lecture Duration (mins.)',
        'Content Quality Grade',
        'Content Quality Remark',
        'Video Quality Grade',
        'Video Quality Remark',
        'Audio Quality Grade',
        'Audio Quality Remark',
        'Slide Quality Grade',
        'Slide Quality Remark',
        'Assessment Time (mins.)'
    ];
    fputcsv($output, $headers, ',', '"', "\\");

    // Sample Row 1
    fputcsv($output, [
        1,
        'Unit 1: Introduction',
        'Overview of Cognitive Psychology',
        'ML',
        'Dr. Sarah Khan',
        45,
        1,
        '',
        1,
        '',
        2,
        'Minor background hiss',
        1,
        '',
        50
    ], ',', '"', "\\");

    // Sample Row 2
    fputcsv($output, [
        2,
        'Unit 1: Introduction',
        'Research Methods in Psychology',
        'EN',
        'Prof. Alex Reed',
        50,
        1,
        '',
        1,
        '',
        1,
        '',
        1,
        '',
        65
    ], ',', '"', "\\");

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv ?: '';
}

/**
 * Generates a clean, valid .xlsx template file using ZipArchive and OpenXML standards.
 * Contains two sheets: "Assessment" (with headers, data validation, sample rows)
 * and "Instructions" (with grade legend, guidelines).
 */
function generate_ld_qa_xlsx_template(): string {
    $zipFile = tempnam(sys_get_temp_dir(), 'lqa_tpl_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create temporary XLSX template.");
    }

    // 1. [Content_Types].xml
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);

    // 2. _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // 3. xl/_rels/workbook.xml.rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // 4. xl/workbook.xml
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Assessment" sheetId="1" r:id="rId1"/>
    <sheet name="Instructions" sheetId="2" r:id="rId2"/>
  </sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);

    // 5. xl/styles.xml
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="3">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="14"/><color rgb="FF1E3A8A"/><name val="Calibri"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0284C7"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD1D5DB"/></left>
      <right style="thin"><color rgb="FFD1D5DB"/></right>
      <top style="thin"><color rgb="FFD1D5DB"/></top>
      <bottom style="thin"><color rgb="FFD1D5DB"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
  </cellXfs>
</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // 6. xl/worksheets/sheet1.xml (Assessment)
    $sheet1 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="1" width="8" customWidth="1"/>
    <col min="2" max="2" width="22" customWidth="1"/>
    <col min="3" max="3" width="35" customWidth="1"/>
    <col min="4" max="4" width="18" customWidth="1"/>
    <col min="5" max="5" width="22" customWidth="1"/>
    <col min="6" max="6" width="24" customWidth="1"/>
    <col min="7" max="7" width="22" customWidth="1"/>
    <col min="8" max="8" width="35" customWidth="1"/>
    <col min="9" max="9" width="20" customWidth="1"/>
    <col min="10" max="10" width="35" customWidth="1"/>
    <col min="11" max="11" width="20" customWidth="1"/>
    <col min="12" max="12" width="35" customWidth="1"/>
    <col min="13" max="13" width="20" customWidth="1"/>
    <col min="14" max="14" width="35" customWidth="1"/>
    <col min="15" max="15" width="25" customWidth="1"/>
  </cols>
  <sheetData>
    <row r="1" ht="28" customHeight="1">
      <c r="A1" s="1" t="inlineStr"><is><t>Sl. No.</t></is></c>
      <c r="B1" s="1" t="inlineStr"><is><t>Chapter</t></is></c>
      <c r="C1" s="1" t="inlineStr"><is><t>Lecture Title</t></is></c>
      <c r="D1" s="1" t="inlineStr"><is><t>Language (ML/EN)</t></is></c>
      <c r="E1" s="1" t="inlineStr"><is><t>Faculty Name</t></is></c>
      <c r="F1" s="1" t="inlineStr"><is><t>Lecture Duration (mins.)</t></is></c>
      <c r="G1" s="1" t="inlineStr"><is><t>Content Quality Grade</t></is></c>
      <c r="H1" s="1" t="inlineStr"><is><t>Content Quality Remark</t></is></c>
      <c r="I1" s="1" t="inlineStr"><is><t>Video Quality Grade</t></is></c>
      <c r="J1" s="1" t="inlineStr"><is><t>Video Quality Remark</t></is></c>
      <c r="K1" s="1" t="inlineStr"><is><t>Audio Quality Grade</t></is></c>
      <c r="L1" s="1" t="inlineStr"><is><t>Audio Quality Remark</t></is></c>
      <c r="M1" s="1" t="inlineStr"><is><t>Slide Quality Grade</t></is></c>
      <c r="N1" s="1" t="inlineStr"><is><t>Slide Quality Remark</t></is></c>
      <c r="O1" s="1" t="inlineStr"><is><t>Assessment Time (mins.)</t></is></c>
    </row>
    <row r="2">
      <c r="A2" s="0"><v>1</v></c>
      <c r="B2" s="0" t="inlineStr"><is><t>Unit 1: Introduction</t></is></c>
      <c r="C2" s="0" t="inlineStr"><is><t>Overview of Cognitive Psychology</t></is></c>
      <c r="D2" s="0" t="inlineStr"><is><t>ML</t></is></c>
      <c r="E2" s="0" t="inlineStr"><is><t>Dr. Sarah Khan</t></is></c>
      <c r="F2" s="0"><v>45</v></c>
      <c r="G2" s="0"><v>1</v></c>
      <c r="H2" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="I2" s="0"><v>1</v></c>
      <c r="J2" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="K2" s="0"><v>2</v></c>
      <c r="L2" s="0" t="inlineStr"><is><t>Minor background hiss</t></is></c>
      <c r="M2" s="0"><v>1</v></c>
      <c r="N2" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="O2" s="0"><v>50</v></c>
    </row>
    <row r="3">
      <c r="A3" s="0"><v>2</v></c>
      <c r="B3" s="0" t="inlineStr"><is><t>Unit 1: Introduction</t></is></c>
      <c r="C3" s="0" t="inlineStr"><is><t>Research Methods in Psychology</t></is></c>
      <c r="D3" s="0" t="inlineStr"><is><t>EN</t></is></c>
      <c r="E3" s="0" t="inlineStr"><is><t>Prof. Alex Reed</t></is></c>
      <c r="F3" s="0"><v>50</v></c>
      <c r="G3" s="0"><v>1</v></c>
      <c r="H3" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="I3" s="0"><v>1</v></c>
      <c r="J3" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="K3" s="0"><v>1</v></c>
      <c r="L3" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="M3" s="0"><v>1</v></c>
      <c r="N3" s="0" t="inlineStr"><is><t></t></is></c>
      <c r="O3" s="0"><v>65</v></c>
    </row>
  </sheetData>a>
  <dataValidations count="5">
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="D2:D1000">
      <formula1>&quot;ML,EN&quot;</formula1>
    </dataValidation>
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="G2:G1000">
      <formula1>&quot;1,2,3&quot;</formula1>
    </dataValidation>
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="I2:I1000">
      <formula1>&quot;1,2,3&quot;</formula1>
    </dataValidation>
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="K2:K1000">
      <formula1>&quot;1,2,3&quot;</formula1>
    </dataValidation>
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="M2:M1000">
      <formula1>&quot;1,2,3&quot;</formula1>
    </dataValidation>
  </dataValidations>
</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);

    // 7. xl/worksheets/sheet2.xml (Instructions)
    $sheet2 = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <cols>
    <col min="1" max="1" width="28" customWidth="1"/>
    <col min="2" max="2" width="65" customWidth="1"/>
  </cols>
  <sheetData>
    <row r="1"><c r="A1" s="2" t="inlineStr"><is><t>PEPP Learning — Lecture Video Quality Assessment Guide</t></is></c></row>
    <row r="3"><c r="A3" s="1" t="inlineStr"><is><t>Grade Scale</t></is></c><c r="B3" s="1" t="inlineStr"><is><t>Meaning and Rule</t></is></c></row>
    <row r="4"><c r="A4" s="0" t="inlineStr"><is><t>Grade 1</t></is></c><c r="B4" s="0" t="inlineStr"><is><t>Good — High quality, clear, and ready. Remark is optional.</t></is></c></row>
    <row r="5"><c r="A5" s="0" t="inlineStr"><is><t>Grade 2</t></is></c><c r="B5" s="0" t="inlineStr"><is><t>Okay — Acceptable but minor issues present. Remark is MANDATORY.</t></is></c></row>
    <row r="6"><c r="A6" s="0" t="inlineStr"><is><t>Grade 3</t></is></c><c r="B6" s="0" t="inlineStr"><is><t>To be improved — Significant defects; re-record/revision candidate. Remark is MANDATORY.</t></is></c></row>
    <row r="8"><c r="A8" s="1" t="inlineStr"><is><t>Field</t></is></c><c r="B8" s="1" t="inlineStr"><is><t>Requirement</t></is></c></row>
    <row r="9"><c r="A9" s="0" t="inlineStr"><is><t>Language</t></is></c><c r="B9" s="0" t="inlineStr"><is><t>Allowed values: ML (Malayalam) or EN (English).</t></is></c></row>
    <row r="10"><c r="A10" s="0" t="inlineStr"><is><t>Lecture Duration (mins.)</t></is></c><c r="B10" s="0" t="inlineStr"><is><t>Actual length of the lecture video in minutes (e.g. 20).</t></is></c></row>
    <row r="11"><c r="A11" s="0" t="inlineStr"><is><t>Assessment Time (mins.)</t></is></c><c r="B11" s="0" t="inlineStr"><is><t>Your actual time spent assessing this lecture (e.g. 5). Payment is calculated strictly from this total.</t></is></c></row>
    <row r="12"><c r="A12" s="0" t="inlineStr"><is><t>Quality Aspects</t></is></c><c r="B12" s="0" t="inlineStr"><is><t>Evaluate all four aspects: Content, Video, Audio, Slide.</t></is></c></row>
  </sheetData>
</worksheet>';
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);

    $zip->close();
    $content = file_get_contents($zipFile);
    @unlink($zipFile);
    return $content ?: '';
}

/**
 * Ensures template files are cached on disk in assets/templates/.
 */
function save_ld_qa_templates_to_disk(?string $assetsDir = null): array {
    $dir = $assetsDir ?: dirname(__DIR__, 2) . '/assets/templates';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $csvFile = $dir . '/Lecture_Quality_Assessment_Template.csv';
    $xlsxFile = $dir . '/Lecture_Quality_Assessment_Template.xlsx';

    if (!file_exists($csvFile)) {
        file_put_contents($csvFile, generate_ld_qa_csv_template());
    }
    if (!file_exists($xlsxFile)) {
        file_put_contents($xlsxFile, generate_ld_qa_xlsx_template());
    }

    return [
        'csv' => $csvFile,
        'xlsx' => $xlsxFile
    ];
}

/**
 * Reads an XLSX file using PHP's native ZipArchive and SimpleXML.
 * Returns raw rows indexed by row number: [rowNumber => [colLetter => cellValue]].
 */
function read_xlsx_raw_rows(string $filePath): array {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new RuntimeException("Could not open spreadsheet as a valid XLSX archive.");
    }

    // 1. Load shared strings
    $sharedStrings = [];
    $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($stringsXml) {
        $xml = @simplexml_load_string($stringsXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                // Collect simple text or concatenated rich-text runs
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)($r->t ?? '');
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // 2. Load primary sheet (xl/worksheets/sheet1.xml)
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) {
        // Fallback search
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/sheet') && str_ends_with($name, '.xml')) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }

    $zip->close();

    if (!$sheetXml) {
        throw new RuntimeException("Could not find worksheet XML inside the XLSX archive.");
    }

    $xml = @simplexml_load_string($sheetXml);
    if (!$xml || !isset($xml->sheetData)) {
        throw new RuntimeException("Invalid or empty sheet data in XLSX archive.");
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rIndex = (int)$row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = preg_replace('/[0-9]/', '', $ref);
            $type = (string)($c['t'] ?? '');
            $val = '';

            if ($type === 's') {
                $sIdx = (int)$c->v;
                $val = $sharedStrings[$sIdx] ?? '';
            } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                $val = (string)$c->is->t;
            } else {
                $val = (string)($c->v ?? '');
            }

            $cells[$col] = trim($val);
        }
        $rows[$rIndex] = $cells;
    }

    ksort($rows);
    return $rows;
}

/**
 * Reads a CSV file returning raw rows indexed by row number: [rowNumber => [colNumber => cellValue]].
 */
function read_csv_raw_rows(string $filePath): array {
    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new RuntimeException("Could not read uploaded CSV file.");
    }

    // Strip UTF-8 BOM if present
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    // Detect delimiter: comma vs semicolon vs tab
    $firstLine = strtok($content, "\r\n") ?: '';
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

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $rows = [];
    $lineNum = 1;
    while (($row = fgetcsv($handle, 0, $bestDelim, '"', "\\")) !== false) {
        $trimmedRow = array_map(function($v) { return trim((string)$v); }, $row);
        $rows[$lineNum] = $trimmedRow;
        $lineNum++;
    }
    fclose($handle);

    return $rows;
}

/**
 * Normalizes a header text for loose matching.
 */
function ld_qa_norm_header(string $header): string {
    $h = strtolower(trim($header));
    $h = str_replace(["\r", "\n", "\t"], ' ', $h);
    $h = preg_replace('/[^a-z0-9]/', '', $h);
    return $h;
}

/**
 * Analyzes raw rows and detects column mapping.
 * Supports:
 * - Standard 15-column single-header format
 * - Legacy 2-level reference format (merged headers on row 3 + sub-headers on row 4)
 */
function detect_ld_qa_columns(array $rawRows): array {
    // Expected conceptual fields
    $fields = [
        'sl_no',
        'chapter',
        'lecture_title',
        'language',
        'faculty_name',
        'lecture_duration_minutes',
        'content_grade',
        'content_remark',
        'video_grade',
        'video_remark',
        'audio_grade',
        'audio_remark',
        'slide_grade',
        'slide_remark',
        'assessment_minutes'
    ];

    // Check up to the first 10 rows for header definitions
    $headerRowIndex = null;
    $isLegacy2Level = false;

    foreach ($rawRows as $rIdx => $cells) {
        // Flatten cell values
        $lineHeaders = array_map('ld_qa_norm_header', array_values($cells));

        // Check for 2-level legacy format
        $hasContentQuality = in_array('contentquality', $lineHeaders, true);
        $hasVideoQuality = in_array('videoquality', $lineHeaders, true);
        $hasDuration = in_array('durationmins', $lineHeaders, true) || in_array('duration', $lineHeaders, true);

        if ($hasContentQuality && $hasVideoQuality) {
            $nextRow = $rawRows[$rIdx + 1] ?? [];
            $nextHeaders = array_map('ld_qa_norm_header', array_values($nextRow));
            if (in_array('grade', $nextHeaders, true) && in_array('remark', $nextHeaders, true)) {
                $headerRowIndex = $rIdx;
                $isLegacy2Level = true;
                break;
            }
        }

        // Check for Standard 15-column format
        $hasStandardContent = in_array('contentqualitygrade', $lineHeaders, true);
        $hasStandardVideo = in_array('videoqualitygrade', $lineHeaders, true);
        $hasAssessmentTime = in_array('assessmenttimemins', $lineHeaders, true) || in_array('assessmenttime', $lineHeaders, true);

        if (($hasStandardContent && $hasStandardVideo) || ($hasAssessmentTime && in_array('chapter', $lineHeaders, true))) {
            $headerRowIndex = $rIdx;
            $isLegacy2Level = false;
            break;
        }
    }

    if ($headerRowIndex === null) {
        return [
            'success' => false,
            'error' => 'Could not identify valid assessment table headers in uploaded spreadsheet.'
        ];
    }

    $colMap = [];
    $dataStartRow = $headerRowIndex + 1;

    if ($isLegacy2Level) {
        $dataStartRow = $headerRowIndex + 2;
        $rowTop = $rawRows[$headerRowIndex];
        $rowSub = $rawRows[$headerRowIndex + 1];

        // Track active section for merged headers across columns
        $activeSection = '';
        foreach ($rowTop as $colKey => $val) {
            $topNorm = ld_qa_norm_header($val);
            if ($topNorm === 'contentquality') $activeSection = 'content';
            elseif ($topNorm === 'videoquality') $activeSection = 'video';
            elseif ($topNorm === 'audioquality') $activeSection = 'audio';
            elseif ($topNorm === 'slidequality') $activeSection = 'slide';
            elseif ($topNorm === 'overallgradingai') $activeSection = 'ai';
            elseif ($topNorm !== '') $activeSection = '';

            $subVal = $rowSub[$colKey] ?? '';
            $subNorm = ld_qa_norm_header($subVal);

            if ($topNorm === 'sl' || $topNorm === 'slno') $colMap['sl_no'] = $colKey;
            elseif ($topNorm === 'chapter') $colMap['chapter'] = $colKey;
            elseif ($topNorm === 'title' || $topNorm === 'lecturetitle') $colMap['lecture_title'] = $colKey;
            elseif (str_starts_with($topNorm, 'language')) $colMap['language'] = $colKey;
            elseif (str_starts_with($topNorm, 'faculty')) $colMap['faculty_name'] = $colKey;
            elseif (str_starts_with($topNorm, 'duration')) $colMap['lecture_duration_minutes'] = $colKey;
            elseif (str_contains($topNorm, 'totaltime') || str_contains($topNorm, 'assessment')) $colMap['assessment_minutes'] = $colKey;

            if ($activeSection === 'content') {
                if ($subNorm === 'grade') $colMap['content_grade'] = $colKey;
                elseif ($subNorm === 'remark') $colMap['content_remark'] = $colKey;
            } elseif ($activeSection === 'video') {
                if ($subNorm === 'grade') $colMap['video_grade'] = $colKey;
                elseif ($subNorm === 'remark') $colMap['video_remark'] = $colKey;
            } elseif ($activeSection === 'audio') {
                if ($subNorm === 'grade') $colMap['audio_grade'] = $colKey;
                elseif ($subNorm === 'remark') $colMap['audio_remark'] = $colKey;
            } elseif ($activeSection === 'slide') {
                if ($subNorm === 'grade') $colMap['slide_grade'] = $colKey;
                elseif ($subNorm === 'remark') $colMap['slide_remark'] = $colKey;
            }
        }
    } else {
        // Flat standard single-row header
        $rowHeaders = $rawRows[$headerRowIndex];
        foreach ($rowHeaders as $colKey => $val) {
            $norm = ld_qa_norm_header($val);
            if ($norm === 'sl' || $norm === 'slno') $colMap['sl_no'] = $colKey;
            elseif ($norm === 'chapter') $colMap['chapter'] = $colKey;
            elseif ($norm === 'lecturetitle' || $norm === 'title') $colMap['lecture_title'] = $colKey;
            elseif (str_starts_with($norm, 'language')) $colMap['language'] = $colKey;
            elseif (str_starts_with($norm, 'faculty')) $colMap['faculty_name'] = $colKey;
            elseif (str_starts_with($norm, 'lectureduration') || $norm === 'durationmins' || $norm === 'duration') $colMap['lecture_duration_minutes'] = $colKey;
            elseif ($norm === 'contentqualitygrade' || $norm === 'contentgrade') $colMap['content_grade'] = $colKey;
            elseif ($norm === 'contentqualityremark' || $norm === 'contentremark') $colMap['content_remark'] = $colKey;
            elseif ($norm === 'videoqualitygrade' || $norm === 'videograde') $colMap['video_grade'] = $colKey;
            elseif ($norm === 'videoqualityremark' || $norm === 'videoremark') $colMap['video_remark'] = $colKey;
            elseif ($norm === 'audioqualitygrade' || $norm === 'audiograde') $colMap['audio_grade'] = $colKey;
            elseif ($norm === 'audioqualityremark' || $norm === 'audioremark') $colMap['audio_remark'] = $colKey;
            elseif ($norm === 'slidequalitygrade' || $norm === 'slidegrade') $colMap['slide_grade'] = $colKey;
            elseif ($norm === 'slidequalityremark' || $norm === 'slideremark') $colMap['slide_remark'] = $colKey;
            elseif ($norm === 'assessmenttimemins' || $norm === 'assessmenttime' || str_contains($norm, 'assessment')) $colMap['assessment_minutes'] = $colKey;
        }
    }

    return [
        'success' => true,
        'col_map' => $colMap,
        'data_start_row' => $dataStartRow,
        'is_legacy' => $isLegacy2Level
    ];
}

/**
 * Validates parsed rows according to the strict specification.
 * Distinguishes:
 * A. File structure errors
 * B. Data errors
 * C. Duplicate errors
 * D. Course reconciliation errors
 * E. Non-blocking warnings
 */
function validate_ld_qa_file(
    string $filePath,
    string $originalFilename,
    string $courseName,
    float $hourlyRate,
    ?PDO $pdo = null,
    int $courseId = 0
): array {
    $errors = [];
    $fileStructureErrors = [];
    $dataErrors = [];
    $duplicateErrors = [];
    $reconciliationErrors = [];
    $warnings = [];

    // 1. File checks
    if (!file_exists($filePath)) {
        $err = 'Uploaded file not found on server.';
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }
    $fileSize = filesize($filePath);
    if ($fileSize > 10 * 1024 * 1024) {
        $err = 'File size exceeds the 10 MB limit.';
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        $err = "Unsupported file format (.{$ext}). Only .xlsx and .csv spreadsheets are accepted.";
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    // Parse rows based on file type
    $rawRows = [];
    try {
        if ($ext === 'xlsx') {
            $rawRows = read_xlsx_raw_rows($filePath);
        } else {
            $rawRows = read_csv_raw_rows($filePath);
        }
    } catch (Exception $e) {
        $err = 'Failed to parse spreadsheet: ' . $e->getMessage();
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    if (empty($rawRows)) {
        $err = 'Spreadsheet contains no readable rows.';
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    // 2. Column mapping detection
    $detection = detect_ld_qa_columns($rawRows);
    if (!$detection['success']) {
        $err = $detection['error'];
        $fileStructureErrors[] = $err;
        $errors[] = $err;
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    $colMap = $detection['col_map'];
    $dataStartRow = $detection['data_start_row'];

    // Verify all required conceptual columns exist
    $requiredColumns = [
        'chapter' => 'Chapter',
        'lecture_title' => 'Lecture Title',
        'language' => 'Language (ML/EN)',
        'faculty_name' => 'Faculty Name',
        'lecture_duration_minutes' => 'Lecture Duration (mins.)',
        'content_grade' => 'Content Quality Grade',
        'content_remark' => 'Content Quality Remark',
        'video_grade' => 'Video Quality Grade',
        'video_remark' => 'Video Quality Remark',
        'audio_grade' => 'Audio Quality Grade',
        'audio_remark' => 'Audio Quality Remark',
        'slide_grade' => 'Slide Quality Grade',
        'slide_remark' => 'Slide Quality Remark',
        'assessment_minutes' => 'Assessment Time (mins.)'
    ];

    foreach ($requiredColumns as $colKey => $displayName) {
        if (!isset($colMap[$colKey])) {
            $err = "Missing variable/column: {$displayName}";
            $fileStructureErrors[] = $err;
            $errors[] = $err;
        }
    }

    if (!empty($errors)) {
        return [
            'valid' => false,
            'errors' => $errors,
            'errors_by_category' => [
                'file_structure' => $fileStructureErrors,
                'data_errors' => [],
                'duplicate_errors' => [],
                'reconciliation_errors' => []
            ],
            'warnings' => []
        ];
    }

    // 3. Row-by-row validation & normalization
    $normalizedRows = [];
    $seenLectureKeys = [];
    $seenTitles = [];

    $totalLectures = 0;
    $totalDuration = 0;
    $totalAssessmentMin = 0;

    foreach ($rawRows as $rIdx => $cells) {
        if ($rIdx < $dataStartRow) {
            continue;
        }

        // Check if row is completely blank
        $hasAnyVal = false;
        foreach ($cells as $v) {
            if (trim((string)$v) !== '') {
                $hasAnyVal = true;
                break;
            }
        }
        if (!$hasAnyVal) {
            // Silently ignore completely blank rows
            continue;
        }

        // Row has at least one value: must validate all required fields
        $rowErrors = [];

        $chapter = trim((string)($cells[$colMap['chapter']] ?? ''));
        $title = trim((string)($cells[$colMap['lecture_title']] ?? ''));
        $langRaw = trim((string)($cells[$colMap['language']] ?? ''));
        $faculty = trim((string)($cells[$colMap['faculty_name']] ?? ''));
        $durationRaw = trim((string)($cells[$colMap['lecture_duration_minutes']] ?? ''));
        $cgRaw = trim((string)($cells[$colMap['content_grade']] ?? ''));
        $cr = trim((string)($cells[$colMap['content_remark']] ?? ''));
        $vgRaw = trim((string)($cells[$colMap['video_grade']] ?? ''));
        $vr = trim((string)($cells[$colMap['video_remark']] ?? ''));
        $agRaw = trim((string)($cells[$colMap['audio_grade']] ?? ''));
        $ar = trim((string)($cells[$colMap['audio_remark']] ?? ''));
        $sgRaw = trim((string)($cells[$colMap['slide_grade']] ?? ''));
        $sr = trim((string)($cells[$colMap['slide_remark']] ?? ''));
        $assMinRaw = trim((string)($cells[$colMap['assessment_minutes']] ?? ''));

        // Chapter
        if ($chapter === '') {
            $rowErrors[] = "Chapter is required";
        }

        // Lecture Title
        if ($title === '') {
            $rowErrors[] = "Lecture Title is required";
        }

        // Language normalization
        $langNorm = strtoupper($langRaw);
        if ($langNorm === 'MALAYALAM') $langNorm = 'ML';
        elseif ($langNorm === 'ENGLISH') $langNorm = 'EN';

        if (!in_array($langNorm, ['ML', 'EN'], true)) {
            $rowErrors[] = "Invalid Language '{$langRaw}'. Allowed values: ML or EN";
        }

        // Faculty Name
        if ($faculty === '') {
            $rowErrors[] = "Faculty Name is required";
        }

        // Lecture Duration
        if (!is_numeric($durationRaw) || (float)$durationRaw <= 0) {
            $rowErrors[] = "Lecture Duration must be a positive numeric value in minutes";
        }
        $duration = (int)round((float)$durationRaw);

        // Assessment Time
        if (!is_numeric($assMinRaw) || (float)$assMinRaw <= 0) {
            $rowErrors[] = "Assessment Time is invalid (must be a positive number of minutes)";
        }
        $assessmentMin = (int)round((float)$assMinRaw);
        if ($assessmentMin > 300) {
            $warnings[] = "Row {$rIdx} — Assessment time ({$assessmentMin} mins) is unusually high (exceeds 5 hours)";
        }
        if ($duration > 0 && $assessmentMin > ($duration * 4)) {
            $warnings[] = "Row {$rIdx} — Assessment time ({$assessmentMin} mins) is significantly higher than lecture duration ({$duration} mins). Please verify the value.";
        }

        // Quality Grades & Remarks
        $aspects = [
            'Content' => [$cgRaw, $cr, 'content_grade', 'content_remark'],
            'Video'   => [$vgRaw, $vr, 'video_grade', 'video_remark'],
            'Audio'   => [$agRaw, $ar, 'audio_grade', 'audio_remark'],
            'Slide'   => [$sgRaw, $sr, 'slide_grade', 'slide_remark']
        ];

        $parsedGrades = [];
        foreach ($aspects as $aspName => [$gRaw, $remark, $gradeField, $remField]) {
            if ($gRaw === '' || !in_array((string)$gRaw, ['1', '2', '3'], true)) {
                $rowErrors[] = "{$aspName} Quality Grade must be 1, 2, or 3";
                $parsedGrades[$gradeField] = 1;
            } else {
                $gradeVal = (int)$gRaw;
                $parsedGrades[$gradeField] = $gradeVal;
                if (($gradeVal === 2 || $gradeVal === 3) && $remark === '') {
                    $rowErrors[] = "{$aspName} Quality Remark is required when Grade is {$gradeVal}";
                }
            }
            $parsedGrades[$remField] = $remark;
        }

        // Duplicate checks within spreadsheet (Section 8: Primary duplicate identity: normalized Chapter + normalized Topic/Lecture Title)
        $normChapter = normalize_ld_qa_text($chapter);
        $normTitle = normalize_ld_qa_text($title);
        $lectureKey = $normChapter . '|' . $normTitle;
        if ($normChapter !== '' && $normTitle !== '') {
            if (isset($seenLectureKeys[$lectureKey])) {
                $prevRow = $seenLectureKeys[$lectureKey];
                $dupMsg = "Row {$rIdx} — Duplicate lecture: Chapter '{$chapter}', Topic '{$title}' was already assessed on Row {$prevRow}";
                $duplicateErrors[] = $dupMsg;
                $errors[] = $dupMsg;
            } else {
                $seenLectureKeys[$lectureKey] = $rIdx;
            }
        }

        // Title-only duplicate warning across different chapters
        $titleLower = strtolower($title);
        if (isset($seenTitles[$titleLower]) && $seenTitles[$titleLower]['chapter'] !== $chapter) {
            $warnings[] = "Row {$rIdx} — Notice: Lecture title '{$title}' also appears in Chapter '{$seenTitles[$titleLower]['chapter']}' (Row {$seenTitles[$titleLower]['row']})";
        } else {
            $seenTitles[$titleLower] = ['row' => $rIdx, 'chapter' => $chapter];
        }

        if (!empty($rowErrors)) {
            foreach ($rowErrors as $re) {
                $fullErr = "Row {$rIdx} — {$re}";
                $dataErrors[] = $fullErr;
                $errors[] = $fullErr;
            }
        } else {
            $normalizedRows[] = [
                'source_row_number' => $rIdx,
                'chapter' => $chapter,
                'lecture_title' => $title,
                'language' => $langNorm,
                'faculty_name' => $faculty,
                'lecture_duration_minutes' => $duration,
                'content_grade' => $parsedGrades['content_grade'],
                'content_remark' => $parsedGrades['content_remark'],
                'video_grade' => $parsedGrades['video_grade'],
                'video_remark' => $parsedGrades['video_remark'],
                'audio_grade' => $parsedGrades['audio_grade'],
                'audio_remark' => $parsedGrades['audio_remark'],
                'slide_grade' => $parsedGrades['slide_grade'],
                'slide_remark' => $parsedGrades['slide_remark'],
                'assessment_minutes' => $assessmentMin,
                'normalized_lecture_key' => $lectureKey
            ];

            $totalLectures++;
            $totalDuration += $duration;
            $totalAssessmentMin += $assessmentMin;
        }
    }

    if ($totalLectures === 0 && empty($errors)) {
        $err = "No assessment lecture rows found in the uploaded file.";
        $dataErrors[] = $err;
        $errors[] = $err;
    }

    $safeRate = $hourlyRate > 0.0 ? $hourlyRate : 0.0;
    $totalHours = $totalAssessmentMin / 60.0;
    $calculatedCharge = round($totalHours * $safeRate, 2);

    $sha256 = hash_file('sha256', $filePath);
    if ($pdo !== null && detect_duplicate_qa_hash($pdo, $sha256)) {
        $dupFileMsg = "Duplicate file: An active assessment report with this exact file content (SHA-256: " . substr($sha256, 0, 10) . "...) has already been uploaded.";
        $duplicateErrors[] = $dupFileMsg;
        $errors[] = $dupFileMsg;
    }

    // Comprehensive deterministic dataset statistics
    $uniqueChapters = array_unique(array_filter(array_map(function($r) {
        return $r['chapter'];
    }, $normalizedRows)));

    $avgDuration = $totalLectures > 0 ? round($totalDuration / $totalLectures, 1) : 0.0;
    $avgAssessmentMin = $totalLectures > 0 ? round($totalAssessmentMin / $totalLectures, 1) : 0.0;

    $aspectCounts = [
        'content' => [1 => 0, 2 => 0, 3 => 0],
        'video'   => [1 => 0, 2 => 0, 3 => 0],
        'audio'   => [1 => 0, 2 => 0, 3 => 0],
        'slide'   => [1 => 0, 2 => 0, 3 => 0]
    ];
    $chapterStats = [];
    $facultyStats = [];
    $languageStats = ['ML' => 0, 'EN' => 0];

    foreach ($normalizedRows as $row) {
        $cg = (int)$row['content_grade'];
        $vg = (int)$row['video_grade'];
        $ag = (int)$row['audio_grade'];
        $sg = (int)$row['slide_grade'];
        if (isset($aspectCounts['content'][$cg])) $aspectCounts['content'][$cg]++;
        if (isset($aspectCounts['video'][$vg])) $aspectCounts['video'][$vg]++;
        if (isset($aspectCounts['audio'][$ag])) $aspectCounts['audio'][$ag]++;
        if (isset($aspectCounts['slide'][$sg])) $aspectCounts['slide'][$sg]++;

        $ch = $row['chapter'];
        if (!isset($chapterStats[$ch])) {
            $chapterStats[$ch] = ['topics' => 0, 'duration' => 0, 'assessment_mins' => 0];
        }
        $chapterStats[$ch]['topics']++;
        $chapterStats[$ch]['duration'] += (int)$row['lecture_duration_minutes'];
        $chapterStats[$ch]['assessment_mins'] += (int)$row['assessment_minutes'];

        $fac = $row['faculty_name'];
        if (!isset($facultyStats[$fac])) {
            $facultyStats[$fac] = ['topics' => 0, 'duration' => 0, 'assessment_mins' => 0];
        }
        $facultyStats[$fac]['topics']++;
        $facultyStats[$fac]['duration'] += (int)$row['lecture_duration_minutes'];
        $facultyStats[$fac]['assessment_mins'] += (int)$row['assessment_minutes'];

        $lang = $row['language'];
        if (!isset($languageStats[$lang])) $languageStats[$lang] = 0;
        $languageStats[$lang]++;
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'errors_by_category' => [
            'file_structure' => $fileStructureErrors,
            'data_errors' => $dataErrors,
            'duplicate_errors' => $duplicateErrors,
            'reconciliation_errors' => []
        ],
        'warnings' => $warnings,
        'rows' => $normalizedRows,
        'stats' => [
            'total_chapters' => count($uniqueChapters),
            'total_lectures' => $totalLectures,
            'total_lecture_duration_minutes' => $totalDuration,
            'average_lecture_duration_minutes' => $avgDuration,
            'total_assessment_minutes' => $totalAssessmentMin,
            'total_assessment_hours' => round($totalHours, 4),
            'average_assessment_minutes_per_topic' => $avgAssessmentMin,
            'hourly_rate' => $safeRate,
            'calculated_charge' => $calculatedCharge,
            'grade_distribution' => $aspectCounts,
            'chapter_summary' => $chapterStats,
            'faculty_summary' => $facultyStats,
            'language_distribution' => $languageStats,
            'sha256_hash' => $sha256,
            'file_size' => $fileSize,
            'file_type' => $ext
        ]
    ];
}

/**
 * Convenience alias for validate_ld_qa_file to support both validation and parsing workflows.
 */
function parse_ld_qa_file(string $filePath, float $hourlyRate = 0.0, string $originalFilename = '', string $courseName = 'Course', ?PDO $pdo = null, int $courseId = 0): array {
    if ($originalFilename === '') {
        $originalFilename = basename($filePath);
    }
    return validate_ld_qa_file($filePath, $originalFilename, $courseName, $hourlyRate, $pdo, $courseId);
}

/**
 * @deprecated Obsolete — The PEPP Learning App and PEPP ERP are completely separate systems without course catalogue integration.
 * This function is not used in the production validation path. The selected course in task-tracker.php is authoritative,
 * and chapters/topics are sourced exclusively from the uploaded assessment file.
 */
function reconcile_ld_qa_course_lectures(PDO $pdo, int $courseId, array $normalizedRows): array {
    $hasCatalogue = false;
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='course_lecture_catalog'");
        } else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'course_lecture_catalog'");
        }
        if ($stmt && $stmt->fetch()) {
            $hasCatalogue = true;
        }
    } catch (Exception $e) {
        $hasCatalogue = false;
    }

    if (!$hasCatalogue) {
        return [
            'catalogue_available' => false,
            'message' => 'Course-level lecture reconciliation is not currently possible because no authoritative lecture catalogue exists in the current ERP architecture.',
            'missing_lectures' => [],
            'unexpected_lectures' => [],
            'metadata_mismatches' => [],
            'reconciliation_errors' => []
        ];
    }

    $stmt = $pdo->prepare("SELECT * FROM course_lecture_catalog WHERE course_id = ? AND status = 'active' ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$courseId]);
    $catalogLectures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($catalogLectures)) {
        return [
            'catalogue_available' => true,
            'message' => 'No active lectures catalogued for this course yet.',
            'missing_lectures' => [],
            'unexpected_lectures' => [],
            'metadata_mismatches' => [],
            'reconciliation_errors' => []
        ];
    }

    $missingLectures = [];
    $unexpectedLectures = [];
    $metadataMismatches = [];
    $reconciliationErrors = [];

    $catalogByKey = [];
    $catalogByTitle = [];
    foreach ($catalogLectures as $cl) {
        $chapterName = $cl['chapter_name'] ?? $cl['chapter'] ?? '';
        $key = strtolower(trim($chapterName)) . '|' . strtolower(trim($cl['lecture_title']));
        $catalogByKey[$key] = $cl;
        $catalogByTitle[strtolower(trim($cl['lecture_title']))] = $cl;
    }

    $uploadedKeys = [];
    foreach ($normalizedRows as $row) {
        $rIdx = $row['source_row_number'];
        $ch = strtolower(trim($row['chapter']));
        $ti = strtolower(trim($row['lecture_title']));
        $key = $ch . '|' . $ti;
        $uploadedKeys[$key] = true;

        if (isset($catalogByKey[$key])) {
            $expected = $catalogByKey[$key];
            if (!empty($expected['faculty_name']) && strtolower(trim($expected['faculty_name'])) !== strtolower(trim($row['faculty_name']))) {
                $err = "Row {$rIdx} — Faculty mismatch: expected '{$expected['faculty_name']}', uploaded '{$row['faculty_name']}'";
                $metadataMismatches[] = [
                    'row' => $rIdx,
                    'field' => 'faculty_name',
                    'expected' => $expected['faculty_name'],
                    'uploaded' => $row['faculty_name'],
                    'message' => $err
                ];
                $reconciliationErrors[] = $err;
            }
            if (!empty($expected['language']) && strtoupper(trim($expected['language'])) !== strtoupper(trim($row['language']))) {
                $err = "Row {$rIdx} — Language mismatch: expected '{$expected['language']}', uploaded '{$row['language']}'";
                $metadataMismatches[] = [
                    'row' => $rIdx,
                    'field' => 'language',
                    'expected' => $expected['language'],
                    'uploaded' => $row['language'],
                    'message' => $err
                ];
                $reconciliationErrors[] = $err;
            }
        } elseif (isset($catalogByTitle[$ti])) {
            $expected = $catalogByTitle[$ti];
            $expChapter = $expected['chapter_name'] ?? $expected['chapter'] ?? '';
            $err = "Row {$rIdx} — Chapter mismatch for lecture '{$row['lecture_title']}': expected in '{$expChapter}', uploaded in '{$row['chapter']}'";
            $metadataMismatches[] = [
                'row' => $rIdx,
                'field' => 'chapter',
                'expected' => $expChapter,
                'uploaded' => $row['chapter'],
                'message' => $err
            ];
            $reconciliationErrors[] = $err;
        } else {
            $err = "Row {$rIdx} — Lecture not found in the selected course: '{$row['lecture_title']}'";
            $unexpectedLectures[] = [
                'row' => $rIdx,
                'lecture_title' => $row['lecture_title'],
                'chapter' => $row['chapter'],
                'message' => $err
            ];
            $reconciliationErrors[] = $err;
        }
    }

    foreach ($catalogLectures as $cl) {
        $chapterName = $cl['chapter_name'] ?? $cl['chapter'] ?? '';
        $key = strtolower(trim($chapterName)) . '|' . strtolower(trim($cl['lecture_title']));
        if (!isset($uploadedKeys[$key])) {
            $err = "Missing expected lecture: '{$cl['lecture_title']}' (Chapter: {$chapterName})";
            $missingLectures[] = [
                'title' => $cl['lecture_title'],
                'chapter' => $chapterName,
                'message' => $err
            ];
        }
    }

    if (!empty($missingLectures)) {
        $reconciliationErrors[] = count($missingLectures) . " expected lecture(s) are missing from the assessment report.";
    }

    return [
        'catalogue_available' => true,
        'missing_lectures' => $missingLectures,
        'unexpected_lectures' => $unexpectedLectures,
        'metadata_mismatches' => $metadataMismatches,
        'reconciliation_errors' => $reconciliationErrors
    ];
}

/**
 * Checks whether an uploaded file hash already exists in ld_quality_assessment_reports.
 */
function detect_duplicate_qa_hash(PDO $pdo, string $sha256): bool {
    try {
        $stmt = $pdo->prepare("SELECT id FROM ld_quality_assessment_reports WHERE sha256_hash = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$sha256]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Executes the complete transactional persistence of an uploaded assessment report.
 * Guaranteed:
 * - Server-authoritative hourly rate check (rejects if <= 0.00).
 * - Real lecture topics only in ld_task_topics (NO synthetic 'Rounding Adjustment' topic).
 * - Submission commits before AI analysis (AI failure never rolls back the valid task).
 * - AI status set to 'pending' upon creation.
 */
function store_ld_quality_assessment(
    PDO $pdo,
    array $validatedData,
    string $tempUploadedPath,
    string $originalFilename,
    array $adminUser,
    int $courseId,
    float $hourlyRate,
    ?float $clientLat = null,
    ?float $clientLon = null,
    ?string $mapsUrl = null,
    ?QualityAssessmentAiService $aiService = null
): array {
    if (empty($validatedData['valid']) || empty($validatedData['rows']) || empty($validatedData['stats'])) {
        throw new InvalidArgumentException("Cannot store invalid assessment data: " . implode('; ', $validatedData['errors'] ?? ['Validation failed']));
    }
    $rows = $validatedData['rows'];
    $stats = $validatedData['stats'];

    // 1. Fetch course details
    $stmt = $pdo->prepare("SELECT course_name FROM ld_work_courses WHERE id = ? AND status = 'active'");
    $stmt->execute([$courseId]);
    $courseName = $stmt->fetchColumn();
    if (!$courseName) {
        throw new RuntimeException("Selected L&D Work Course is invalid or inactive.");
    }

    // 2. Fetch server-authoritative system work mode & rate
    $mode = get_ld_quality_assessment_mode($pdo);
    if (!$mode) {
        throw new RuntimeException("System Work Mode 'Lectures: Quality Assessment' is not configured.");
    }
    $modeId = (int)$mode['id'];
    $modeName = $mode['mode_name'];
    $rateSnapshot = (float)$mode['charge_per_quantity'];

    // STRICT BUSINESS RULE: Rate must be configured (> 0.00) in database by Super Admin
    if ($rateSnapshot <= 0.00) {
        throw new RuntimeException("Please configure the hourly assessment charge for Lectures: Quality Assessment before submitting this task.");
    }

    // 3. Target permanent upload storage
    $uploadDir = dirname(__DIR__, 2) . '/uploads/ld_quality_assessments/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $sha256 = $stats['sha256_hash'];
    $storedFilename = 'LQA_' . date('Ymd_His') . '_' . substr($sha256, 0, 10) . '.' . $ext;
    $targetPath = $uploadDir . $storedFilename;
    $relativeStoredPath = 'uploads/ld_quality_assessments/' . $storedFilename;

    if (!copy($tempUploadedPath, $targetPath)) {
        throw new RuntimeException("Failed to preserve uploaded assessment spreadsheet in secure storage.");
    }

    // 4. Begin Database Transaction
    $pdo->beginTransaction();
    try {
        // Unique report reference
        $monthPrefix = 'LQA-' . date('Ym') . '-';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ld_quality_assessment_reports WHERE report_reference LIKE ?");
        $stmt->execute([$monthPrefix . '%']);
        $seq = (int)$stmt->fetchColumn() + 1;
        $reportRef = $monthPrefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        // Authoritative calculation
        $totalMinutes = (int)$stats['total_assessment_minutes'];
        $totalHours = round($totalMinutes / 60.0, 4);
        $calculatedCharge = round(($totalMinutes / 60.0) * $rateSnapshot, 2);

        // Insert report header (Initial AI status: pending)
        $valStatus = empty($validatedData['warnings']) ? 'passed' : 'warnings';
        $valNotes = !empty($validatedData['warnings']) ? implode("\n", $validatedData['warnings']) : null;

        $stmt = $pdo->prepare("
            INSERT INTO ld_quality_assessment_reports (
                task_id, parent_report_id, version, is_active, admin_id, admin_username, admin_name, course_id, course_name_snapshot,
                report_reference, original_filename, stored_path, file_type, file_size, sha256_hash,
                row_count, total_lecture_duration_minutes, total_assessment_minutes, total_assessment_hours,
                hourly_rate_snapshot, calculated_charge, validation_status, validation_notes,
                ai_status, admin_review_status, created_at, updated_at
            ) VALUES (
                NULL, NULL, 1, 1, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                'pending', 'Pending Review', NOW(), NOW()
            )
        ");
        $stmt->execute([
            $adminUser['id'],
            $adminUser['username'],
            $adminUser['full_name'],
            $courseId,
            $courseName,
            $reportRef,
            $originalFilename,
            $relativeStoredPath,
            $stats['file_type'],
            $stats['file_size'],
            $sha256,
            $stats['total_lectures'],
            $stats['total_lecture_duration_minutes'],
            $totalMinutes,
            $totalHours,
            $rateSnapshot,
            $calculatedCharge,
            $valStatus,
            $valNotes
        ]);

        $reportId = (int)$pdo->lastInsertId();

        // Insert assessment items
        $stmtItem = $pdo->prepare("
            INSERT INTO ld_quality_assessment_items (
                report_id, source_row_number, chapter, lecture_title, language, faculty_name,
                lecture_duration_minutes, content_grade, content_remark, video_grade, video_remark,
                audio_grade, audio_remark, slide_grade, slide_remark, assessment_minutes,
                normalized_lecture_key, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, NOW()
            )
        ");

        foreach ($rows as $item) {
            $stmtItem->execute([
                $reportId,
                $item['source_row_number'],
                $item['chapter'],
                $item['lecture_title'],
                $item['language'],
                $item['faculty_name'],
                $item['lecture_duration_minutes'],
                $item['content_grade'],
                $item['content_remark'] ?: null,
                $item['video_grade'],
                $item['video_remark'] ?: null,
                $item['audio_grade'],
                $item['audio_remark'] ?: null,
                $item['slide_grade'],
                $item['slide_remark'] ?: null,
                $item['assessment_minutes'],
                $item['normalized_lecture_key']
            ]);
        }

        // 5. Create compatible ld_tasks record
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System';

        $stmtTask = $pdo->prepare("
            INSERT INTO ld_tasks (
                admin_id, admin_username, admin_name, admin_role, course_id, course_name,
                mode_id, mode_name, latitude, longitude, maps_url, ip_address, user_agent,
                status, created_at, quantity_label_snapshot, charge_per_quantity_snapshot, mode_name_snapshot
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                'active', NOW(), ?, ?, ?
            )
        ");
        $stmtTask->execute([
            $adminUser['id'],
            $adminUser['username'],
            $adminUser['full_name'],
            $adminUser['role'] ?? 'intern',
            $courseId,
            $courseName,
            $modeId,
            $modeName,
            $clientLat,
            $clientLon,
            $mapsUrl,
            $ip,
            $ua,
            LD_QA_QUANTITY_LABEL,
            $rateSnapshot,
            $modeName
        ]);

        $taskId = (int)$pdo->lastInsertId();

        // Link task_id to report
        $pdo->prepare("UPDATE ld_quality_assessment_reports SET task_id = ? WHERE id = ?")->execute([$taskId, $reportId]);

        // 6. Create compatible ld_task_topics
        // Represent each real lecture as a topic (NO artificial rounding topics)
        $stmtTopic = $pdo->prepare("
            INSERT INTO ld_task_topics (
                task_id, topic_name, quantity, calculated_charge
            ) VALUES (
                ?, ?, ?, ?
            )
        ");

        $runningCharge = 0.00;
        $totalItems = count($rows);
        $cleanTopicsForAudit = [];

        foreach ($rows as $i => $item) {
            $topicName = $item['chapter'] . ': ' . $item['lecture_title'];
            $hours = round($item['assessment_minutes'] / 60.0, 4);

            if ($i === $totalItems - 1) {
                // Ensure exact penny match for calculated charge across real lectures
                $topicCharge = round($calculatedCharge - $runningCharge, 2);
            } else {
                $topicCharge = round(($item['assessment_minutes'] / 60.0) * $rateSnapshot, 2);
                $runningCharge += $topicCharge;
            }

            $stmtTopic->execute([
                $taskId,
                $topicName,
                $hours,
                $topicCharge
            ]);

            $cleanTopicsForAudit[] = [
                'topic_name' => $topicName,
                'quantity' => $hours,
                'calculated_charge' => $topicCharge
            ];
        }

        // 7. Write audit record
        if (function_exists('log_ld_audit')) {
            $auditData = [
                'id' => $taskId,
                'report_id' => $reportId,
                'report_reference' => $reportRef,
                'course_name' => $courseName,
                'mode_name' => $modeName,
                'lectures_assessed' => $stats['total_lectures'],
                'total_assessment_hours' => $totalHours,
                'calculated_charge' => $calculatedCharge,
                'topics' => $cleanTopicsForAudit
            ];
            log_ld_audit($pdo, $taskId, (int)$adminUser['id'], $adminUser['username'], 'CREATE_QUALITY_ASSESSMENT', null, $auditData, $clientLat, $clientLon, $mapsUrl);
        }

        // Commit database transaction - task is 100% saved here
        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        throw $e;
    }

    // 8. Run AI evaluation post-commit (completely independent; failure never rolls back the task)
    $aiStatus = 'pending';
    $aiResult = null;
    try {
        $hasKey = false;
        try {
            $s = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'gemini_api_key' LIMIT 1");
            $s->execute();
            $k = $s->fetchColumn();
            $hasKey = !empty($k) && trim((string)$k) !== '';
        } catch (Exception $e) {}

        if ($aiService !== null || $hasKey) {
            $service = $aiService ?: new QualityAssessmentAiService($pdo);
            $aiOutcome = $service->analyzeAssessmentReport($pdo, $reportId);
            $aiStatus = $aiOutcome['status'] ?? 'pending';
            $aiResult = $aiOutcome['result'] ?? null;
        } else {
            $aiStatus = 'pending_config';
            $pdo->prepare("UPDATE ld_quality_assessment_reports SET ai_status = 'pending_config' WHERE id = ?")->execute([$reportId]);
        }
    } catch (Throwable $aiEx) {
        error_log("Post-upload AI evaluation notice: " . $aiEx->getMessage());
        $aiStatus = 'failed';
        try {
            $pdo->prepare("UPDATE ld_quality_assessment_reports SET ai_status = 'failed', ai_summary = ? WHERE id = ?")
                ->execute(['AI processing notice: ' . $aiEx->getMessage(), $reportId]);
        } catch (Throwable $ignore) {}
    }

    return [
        'success' => true,
        'report_id' => $reportId,
        'task_id' => $taskId,
        'report_reference' => $reportRef,
        'ai_status' => $aiStatus,
        'ai_result' => $aiResult,
        'stats' => $stats
    ];
}

/**
 * Replaces an existing quality assessment report on an unlocked task.
 * Guarantees:
 * - Blocked if the task is paid/financially locked.
 * - Previous version is archived (is_active = 0) and NEVER overwritten or deleted.
 * - New version is assigned version = prev + 1, parent_report_id, is_active = 1.
 * - Full audit trail and multi-version history preserved.
 */
function replace_ld_quality_assessment(
    PDO $pdo,
    int $taskId,
    array $validatedData,
    string $tempUploadedPath,
    string $originalFilename,
    array $adminUser,
    ?float $clientLat = null,
    ?float $clientLon = null,
    ?string $mapsUrl = null,
    ?QualityAssessmentAiService $aiService = null
): array {
    if (empty($validatedData['valid']) || empty($validatedData['rows']) || empty($validatedData['stats'])) {
        throw new InvalidArgumentException("Cannot replace with invalid assessment data: " . implode('; ', $validatedData['errors'] ?? ['Validation failed']));
    }
    $rows = $validatedData['rows'];
    $stats = $validatedData['stats'];

    // 1. Verify task exists
    $stmt = $pdo->prepare("SELECT * FROM ld_tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        throw new RuntimeException("Task not found.");
    }

    // 2. FINANCIAL LOCK CHECK: If task is paid and locked, reject replacement
    $taskDate = date('Y-m-d', strtotime($task['created_at']));
    if (function_exists('is_ld_task_locked')) {
        $lockInfo = is_ld_task_locked($pdo, (int)$task['admin_id'], $taskDate);
        if ($lockInfo) {
            throw new RuntimeException("This task has been paid and financially locked. Re-uploading or replacing the assessment report is not permitted.");
        }
    }

    // 3. Authorization check
    $userRole = $adminUser['role'] ?? 'intern';
    $canManage = ($userRole === 'super_admin' || $userRole === 'admin' || (string)$task['admin_id'] === (string)$adminUser['id']);
    if (!$canManage) {
        throw new RuntimeException("Access denied. You are not authorized to replace this task's report.");
    }

    // 4. Find existing active report
    $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE task_id = ? AND is_active = 1 ORDER BY version DESC LIMIT 1");
    $stmt->execute([$taskId]);
    $prevReport = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prevReport) {
        $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE task_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$taskId]);
        $prevReport = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$prevReport) {
        throw new RuntimeException("No existing assessment report found for this task.");
    }

    // 5. Fetch server-authoritative rate
    $rateSnapshot = (float)$prevReport['hourly_rate_snapshot'];
    if ($rateSnapshot <= 0.00) {
        $mode = get_ld_quality_assessment_mode($pdo);
        $rateSnapshot = $mode ? (float)$mode['charge_per_quantity'] : 0.00;
    }
    if ($rateSnapshot <= 0.00) {
        throw new RuntimeException("Please configure the hourly assessment charge for Lectures: Quality Assessment before submitting this task.");
    }

    // 6. Target permanent upload storage
    $uploadDir = dirname(__DIR__, 2) . '/uploads/ld_quality_assessments/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);
    $sha256 = $stats['sha256_hash'];
    $newVersion = (int)($prevReport['version'] ?? 1) + 1;
    $parentReportId = !empty($prevReport['parent_report_id']) ? (int)$prevReport['parent_report_id'] : (int)$prevReport['id'];
    $storedFilename = 'LQA_v' . $newVersion . '_' . date('Ymd_His') . '_' . substr($sha256, 0, 10) . '.' . $ext;
    $targetPath = $uploadDir . $storedFilename;
    $relativeStoredPath = 'uploads/ld_quality_assessments/' . $storedFilename;

    if (!copy($tempUploadedPath, $targetPath)) {
        throw new RuntimeException("Failed to preserve uploaded replacement spreadsheet.");
    }

    // 7. Transactional persistence
    $pdo->beginTransaction();
    try {
        // Mark all prior reports for this task as inactive
        $pdo->prepare("UPDATE ld_quality_assessment_reports SET is_active = 0, updated_at = NOW() WHERE task_id = ?")
            ->execute([$taskId]);

        $baseRef = preg_replace('/-v\d+$/', '', $prevReport['report_reference']);
        $newRef = $baseRef . '-v' . $newVersion;

        $totalMinutes = (int)$stats['total_assessment_minutes'];
        $totalHours = round($totalMinutes / 60.0, 4);
        $calculatedCharge = round(($totalMinutes / 60.0) * $rateSnapshot, 2);

        $valStatus = empty($validatedData['warnings']) ? 'passed' : 'warnings';
        $valNotes = !empty($validatedData['warnings']) ? implode("\n", $validatedData['warnings']) : null;

        // Insert new version report
        $stmt = $pdo->prepare("
            INSERT INTO ld_quality_assessment_reports (
                task_id, parent_report_id, version, is_active, admin_id, admin_username, admin_name, course_id, course_name_snapshot,
                report_reference, original_filename, stored_path, file_type, file_size, sha256_hash,
                row_count, total_lecture_duration_minutes, total_assessment_minutes, total_assessment_hours,
                hourly_rate_snapshot, calculated_charge, validation_status, validation_notes,
                ai_status, admin_review_status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, 1, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                'pending', 'Pending Review', NOW(), NOW()
            )
        ");
        $stmt->execute([
            $taskId,
            $parentReportId,
            $newVersion,
            $adminUser['id'],
            $adminUser['username'],
            $adminUser['full_name'],
            $prevReport['course_id'],
            $prevReport['course_name_snapshot'],
            $newRef,
            $originalFilename,
            $relativeStoredPath,
            $stats['file_type'],
            $stats['file_size'],
            $sha256,
            $stats['total_lectures'],
            $stats['total_lecture_duration_minutes'],
            $totalMinutes,
            $totalHours,
            $rateSnapshot,
            $calculatedCharge,
            $valStatus,
            $valNotes
        ]);

        $reportId = (int)$pdo->lastInsertId();

        // Insert new items linked to new report
        $stmtItem = $pdo->prepare("
            INSERT INTO ld_quality_assessment_items (
                report_id, source_row_number, chapter, lecture_title, language, faculty_name,
                lecture_duration_minutes, content_grade, content_remark, video_grade, video_remark,
                audio_grade, audio_remark, slide_grade, slide_remark, assessment_minutes,
                normalized_lecture_key, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, NOW()
            )
        ");

        foreach ($rows as $item) {
            $stmtItem->execute([
                $reportId,
                $item['source_row_number'],
                $item['chapter'],
                $item['lecture_title'],
                $item['language'],
                $item['faculty_name'],
                $item['lecture_duration_minutes'],
                $item['content_grade'],
                $item['content_remark'] ?: null,
                $item['video_grade'],
                $item['video_remark'] ?: null,
                $item['audio_grade'],
                $item['audio_remark'] ?: null,
                $item['slide_grade'],
                $item['slide_remark'] ?: null,
                $item['assessment_minutes'],
                $item['normalized_lecture_key']
            ]);
        }

        // Replace task topics with new version's real lectures (NO fake rounding topic)
        $pdo->prepare("DELETE FROM ld_task_topics WHERE task_id = ?")->execute([$taskId]);

        $stmtTopic = $pdo->prepare("
            INSERT INTO ld_task_topics (
                task_id, topic_name, quantity, calculated_charge
            ) VALUES (
                ?, ?, ?, ?
            )
        ");

        $runningCharge = 0.00;
        $totalItems = count($rows);
        $cleanTopicsForAudit = [];

        foreach ($rows as $i => $item) {
            $topicName = $item['chapter'] . ': ' . $item['lecture_title'];
            $hours = round($item['assessment_minutes'] / 60.0, 4);

            if ($i === $totalItems - 1) {
                $topicCharge = round($calculatedCharge - $runningCharge, 2);
            } else {
                $topicCharge = round(($item['assessment_minutes'] / 60.0) * $rateSnapshot, 2);
                $runningCharge += $topicCharge;
            }

            $stmtTopic->execute([
                $taskId,
                $topicName,
                $hours,
                $topicCharge
            ]);

            $cleanTopicsForAudit[] = [
                'topic_name' => $topicName,
                'quantity' => $hours,
                'calculated_charge' => $topicCharge
            ];
        }

        // Update task timestamp
        $pdo->prepare("UPDATE ld_tasks SET updated_at = NOW() WHERE id = ?")->execute([$taskId]);

        // Audit log
        if (function_exists('log_ld_audit')) {
            $auditData = [
                'id' => $taskId,
                'report_id' => $reportId,
                'report_reference' => $newRef,
                'version' => $newVersion,
                'previous_report_id' => $prevReport['id'],
                'previous_version' => $prevReport['version'],
                'lectures_assessed' => $stats['total_lectures'],
                'total_assessment_hours' => $totalHours,
                'calculated_charge' => $calculatedCharge
            ];
            log_ld_audit($pdo, $taskId, (int)$adminUser['id'], $adminUser['username'], 'REPLACE_QUALITY_ASSESSMENT', null, $auditData, $clientLat, $clientLon, $mapsUrl);
        }

        $pdo->commit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
        throw $e;
    }

    // 8. Post-commit AI processing
    $aiStatus = 'pending';
    $aiResult = null;
    try {
        $hasKey = false;
        try {
            $s = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'gemini_api_key' LIMIT 1");
            $s->execute();
            $k = $s->fetchColumn();
            $hasKey = !empty($k) && trim((string)$k) !== '';
        } catch (Exception $e) {}

        if ($aiService !== null || $hasKey) {
            $service = $aiService ?: new QualityAssessmentAiService($pdo);
            $aiOutcome = $service->analyzeAssessmentReport($pdo, $reportId);
            $aiStatus = $aiOutcome['status'] ?? 'pending';
            $aiResult = $aiOutcome['result'] ?? null;
        } else {
            $aiStatus = 'pending_config';
            $pdo->prepare("UPDATE ld_quality_assessment_reports SET ai_status = 'pending_config' WHERE id = ?")->execute([$reportId]);
        }
    } catch (Throwable $aiEx) {
        error_log("Post-upload AI evaluation notice: " . $aiEx->getMessage());
        $aiStatus = 'failed';
        try {
            $pdo->prepare("UPDATE ld_quality_assessment_reports SET ai_status = 'failed', ai_summary = ? WHERE id = ?")
                ->execute(['AI processing notice: ' . $aiEx->getMessage(), $reportId]);
        } catch (Throwable $ignore) {}
    }

    return [
        'success' => true,
        'report_id' => $reportId,
        'task_id' => $taskId,
        'version' => $newVersion,
        'report_reference' => $newRef,
        'ai_status' => $aiStatus,
        'ai_result' => $aiResult,
        'stats' => $stats
    ];
}

/**
 * Returns full version history for a given report or task.
 */
function get_ld_quality_assessment_history(PDO $pdo, int $id, bool $byTaskId = false): array {
    try {
        if ($byTaskId) {
            $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE task_id = ? ORDER BY version ASC, id ASC");
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT task_id, parent_report_id, id FROM ld_quality_assessment_reports WHERE id = ?");
            $stmt->execute([$id]);
            $rep = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rep) return [];

            if (!empty($rep['task_id'])) {
                $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE task_id = ? ORDER BY version ASC, id ASC");
                $stmt->execute([$rep['task_id']]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rootId = !empty($rep['parent_report_id']) ? (int)$rep['parent_report_id'] : (int)$rep['id'];
                $stmt = $pdo->prepare("SELECT * FROM ld_quality_assessment_reports WHERE id = ? OR parent_report_id = ? ORDER BY version ASC, id ASC");
                $stmt->execute([$rootId, $rootId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {
        error_log("get_ld_quality_assessment_history error: " . $e->getMessage());
        return [];
    }
}

/**
 * Processes pending QA AI evaluation reports in background or via queue.
 */
function process_pending_qa_ai_reports(PDO $pdo, int $limit = 5): array {
    $results = [];
    try {
        $stmt = $pdo->prepare("SELECT id FROM ld_quality_assessment_reports WHERE ai_status = 'pending' ORDER BY id ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $reportIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($reportIds)) {
            return ['processed' => 0, 'results' => []];
        }

        $service = new QualityAssessmentAiService($pdo);
        foreach ($reportIds as $rId) {
            try {
                $out = $service->processReport((int)$rId);
                $results[$rId] = $out;
            } catch (Throwable $e) {
                $results[$rId] = ['status' => 'failed', 'error' => $e->getMessage()];
                try {
                    $pdo->prepare("UPDATE ld_quality_assessment_reports SET ai_status = 'failed', ai_summary = ? WHERE id = ?")
                        ->execute(['AI evaluation error: ' . $e->getMessage(), $rId]);
                } catch (Throwable $ig) {}
            }
        }
    } catch (Exception $e) {
        error_log("process_pending_qa_ai_reports error: " . $e->getMessage());
    }

    return ['processed' => count($results), 'results' => $results];
}
