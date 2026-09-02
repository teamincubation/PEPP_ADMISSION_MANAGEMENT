<?php
/**
 * PEPP Learning ERP — Mentor Performance Report PDF Generator
 *
 * Dependency-free, print-quality multi-page PDF generator (PDF 1.4).
 * Generates an official, structured report for academic administration and management.
 */

declare(strict_types=1);

if (!class_exists('MentorReportPDFWriter')) {

    class MentorReportFontInfo {
        public $unitsPerEm = 1000;
        public $ascent = 800;
        public $descent = -200;
        public $widths = [];
        public $bbox = [-1000, -1000, 1000, 1000];

        public function __construct(?string $path) {
            if (!$path || !file_exists($path)) return;
            $data = @file_get_contents($path);
            if (!$data) return;
            $this->parse($data);
        }

        private function parse(string $data): void {
            $numTables = $this->unpackWord($data, 4);
            $tables = [];
            $offset = 12;
            $dataLen = strlen($data);
            for ($i = 0; $i < $numTables; $i++) {
                if ($offset + 16 > $dataLen) break;
                $tag = substr($data, $offset, 4);
                $tableOffset = $this->unpackDWord($data, $offset + 8);
                $length = $this->unpackDWord($data, $offset + 12);
                $tables[$tag] = ['offset' => $tableOffset, 'length' => $length];
                $offset += 16;
            }

            if (isset($tables['head'])) {
                $headOffset = $tables['head']['offset'];
                if ($headOffset + 44 <= $dataLen) {
                    $this->unitsPerEm = $this->unpackWord($data, $headOffset + 18);
                    if ($this->unitsPerEm <= 0) $this->unitsPerEm = 1000;
                    $xMin = $this->unpackShort($data, $headOffset + 36);
                    $yMin = $this->unpackShort($data, $headOffset + 38);
                    $xMax = $this->unpackShort($data, $headOffset + 40);
                    $yMax = $this->unpackShort($data, $headOffset + 42);
                    $this->bbox = [
                        round(($xMin / $this->unitsPerEm) * 1000),
                        round(($yMin / $this->unitsPerEm) * 1000),
                        round(($xMax / $this->unitsPerEm) * 1000),
                        round(($yMax / $this->unitsPerEm) * 1000)
                    ];
                }
            }

            $numberOfHMetrics = 0;
            if (isset($tables['hhea'])) {
                $hheaOffset = $tables['hhea']['offset'];
                if ($hheaOffset + 36 <= $dataLen) {
                    $asc = $this->unpackShort($data, $hheaOffset + 4);
                    $desc = $this->unpackShort($data, $hheaOffset + 6);
                    $this->ascent = round(($asc / $this->unitsPerEm) * 1000);
                    $this->descent = round(($desc / $this->unitsPerEm) * 1000);
                    $numberOfHMetrics = $this->unpackWord($data, $hheaOffset + 34);
                }
            }

            $glyphMap = [];
            if (isset($tables['cmap'])) {
                $cmapOffset = $tables['cmap']['offset'];
                if ($cmapOffset + 4 <= $dataLen) {
                    $numSubtables = $this->unpackWord($data, $cmapOffset + 2);
                    $subtableOffset = 0;
                    for ($i = 0; $i < $numSubtables; $i++) {
                        $pOffset = $cmapOffset + 4 + $i * 8;
                        if ($pOffset + 8 > $dataLen) break;
                        $platformId = $this->unpackWord($data, $pOffset);
                        $encodingId = $this->unpackWord($data, $pOffset + 2);
                        $offsetVal = $this->unpackDWord($data, $pOffset + 4);
                        if (($platformId == 3 && $encodingId == 1) || $platformId == 0) {
                            $subtableOffset = $cmapOffset + $offsetVal;
                            break;
                        }
                    }

                    if ($subtableOffset > 0 && $subtableOffset + 6 <= $dataLen) {
                        $format = $this->unpackWord($data, $subtableOffset);
                        if ($format == 4 && $subtableOffset + 14 <= $dataLen) {
                            $segCount = $this->unpackWord($data, $subtableOffset + 6) / 2;
                            $endCountOffset = $subtableOffset + 14;
                            $startCountOffset = $endCountOffset + $segCount * 2 + 2;
                            $idDeltaOffset = $startCountOffset + $segCount * 2;
                            $idRangeOffset = $idDeltaOffset + $segCount * 2;

                            for ($c = 32; $c <= 255; $c++) {
                                for ($s = 0; $s < $segCount; $s++) {
                                    $endCode = $this->unpackWord($data, $endCountOffset + $s * 2);
                                    $startCode = $this->unpackWord($data, $startCountOffset + $s * 2);
                                    if ($c >= $startCode && $c <= $endCode) {
                                        $idDelta = $this->unpackShort($data, $idDeltaOffset + $s * 2);
                                        $idRange = $this->unpackWord($data, $idRangeOffset + $s * 2);
                                        if ($idRange == 0) {
                                            $glyphMap[$c] = ($c + $idDelta) & 0xFFFF;
                                        } else {
                                            $glyphOffset = $idRangeOffset + $s * 2 + $idRange + ($c - $startCode) * 2;
                                            if ($glyphOffset + 2 <= $dataLen) {
                                                $glyphIndex = $this->unpackWord($data, $glyphOffset);
                                                $glyphMap[$c] = $glyphIndex != 0 ? ($glyphIndex + $idDelta) & 0xFFFF : 0;
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (isset($tables['hmtx']) && $numberOfHMetrics > 0) {
                $hmtxOffset = $tables['hmtx']['offset'];
                for ($c = 32; $c <= 255; $c++) {
                    $gid = $glyphMap[$c] ?? 0;
                    if ($gid < $numberOfHMetrics) {
                        $mOffset = $hmtxOffset + $gid * 4;
                        if ($mOffset + 2 <= $dataLen) {
                            $adv = $this->unpackWord($data, $mOffset);
                            $this->widths[$c] = round(($adv / $this->unitsPerEm) * 1000);
                        }
                    } else {
                        $lastAdvOffset = $hmtxOffset + ($numberOfHMetrics - 1) * 4;
                        if ($lastAdvOffset + 2 <= $dataLen) {
                            $adv = $this->unpackWord($data, $lastAdvOffset);
                            $this->widths[$c] = round(($adv / $this->unitsPerEm) * 1000);
                        }
                    }
                }
            }
        }

        private function unpackWord(string $data, int $offset): int {
            if ($offset + 2 > strlen($data)) return 0;
            $un = unpack('n', substr($data, $offset, 2));
            return (int)($un[1] ?? 0);
        }

        private function unpackShort(string $data, int $offset): int {
            if ($offset + 2 > strlen($data)) return 0;
            $v = $this->unpackWord($data, $offset);
            return ($v >= 0x8000) ? $v - 0x10000 : $v;
        }

        private function unpackDWord(string $data, int $offset): int {
            if ($offset + 4 > strlen($data)) return 0;
            $un = unpack('N', substr($data, $offset, 4));
            return (int)($un[1] ?? 0);
        }
    }

    class MentorReportPDFWriter {
        const W = 595.28; // A4 width in points
        const H = 841.89; // A4 height in points

        private array $pages = [];
        private int $currentPageIndex = -1;
        private array $images = [];
        private int $nextImageId = 1;
        private array $fonts = [];
        private array $fontPaths = [];

        public function __construct() {
            $this->addPage();

            $base_dir = dirname(__DIR__);
            $regularPath  = $base_dir . '/assets/fonts/GoogleSansFlex-Regular.ttf';
            $mediumPath   = $base_dir . '/assets/fonts/GoogleSansFlex-Medium.ttf';
            $semiBoldPath = $base_dir . '/assets/fonts/GoogleSansFlex-SemiBold.ttf';
            $boldPath     = $base_dir . '/assets/fonts/GoogleSansFlex-Bold.ttf';

            $this->fontPaths = [
                'F1' => file_exists($regularPath) ? $regularPath : null,
                'F2' => file_exists($mediumPath) ? $mediumPath : null,
                'F3' => file_exists($semiBoldPath) ? $semiBoldPath : null,
                'F4' => file_exists($boldPath) ? $boldPath : null,
            ];

            $this->fonts['F1'] = new MentorReportFontInfo($this->fontPaths['F1']);
            $this->fonts['F2'] = new MentorReportFontInfo($this->fontPaths['F2']);
            $this->fonts['F3'] = new MentorReportFontInfo($this->fontPaths['F3']);
            $this->fonts['F4'] = new MentorReportFontInfo($this->fontPaths['F4']);
        }

        public function addPage(): void {
            $this->pages[] = [
                'ops' => '',
                'images' => []
            ];
            $this->currentPageIndex = count($this->pages) - 1;
        }

        public function getPageCount(): int {
            return count($this->pages);
        }

        public function setPage(int $index): void {
            if (isset($this->pages[$index])) {
                $this->currentPageIndex = $index;
            }
        }

        public function esc(string $s): string {
            // Replace common UTF-8 symbols with Latin-1 equivalents
            $replacements = [
                "\xE2\x80\x98" => "'",  // ‘
                "\xE2\x80\x99" => "'",  // ’
                "\xE2\x80\x9C" => '"',  // “
                "\xE2\x80\x9D" => '"',  // ”
                "\xE2\x80\x93" => "-",  // – (en dash)
                "\xE2\x80\x94" => "-",  // — (em dash)
                "\xE2\x80\xA2" => "*",  // •
                "\xE2\x82\xB9" => "Rs.", // ₹
                "\xC2\xA0"     => " ",  // non-breaking space
            ];
            $s = strtr($s, $replacements);
            $clean = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
            if ($clean === false) $clean = preg_replace('/[^\x20-\x7E]/', '', $s);
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
        }

        public function width(string $txt, float $size, $weight = 400): float {
            if ($weight === true) $weight = 700;
            elseif ($weight === false || $weight === null) $weight = 400;

            $f = 'F1';
            if ($weight == 500) $f = 'F2';
            elseif ($weight == 600) $f = 'F3';
            elseif ($weight >= 700) $f = 'F4';

            $font = $this->fonts[$f] ?? null;
            $totalWidth = 0;
            $len = strlen($txt);
            for ($i = 0; $i < $len; $i++) {
                $char = ord($txt[$i]);
                $w = ($font && isset($font->widths[$char])) ? $font->widths[$char] : 520;
                $totalWidth += $w;
            }
            return ($totalWidth / 1000.0) * $size;
        }

        public function text(float $x, float $y, float $size, string $txt, $weight = 400, string $align = 'L', float $w = 0): void {
            $tw = $this->width($txt, $size, $weight);
            if ($align === 'C') $x += max(0, ($w - $tw) / 2);
            elseif ($align === 'R') $x += max(0, $w - $tw);
            $py = self::H - $y - $size * 0.78;

            if ($weight === true) $weight = 700;
            elseif ($weight === false || $weight === null) $weight = 400;

            $f = 'F1';
            if ($weight == 500) $f = 'F2';
            elseif ($weight == 600) $f = 'F3';
            elseif ($weight >= 700) $f = 'F4';

            $this->pages[$this->currentPageIndex]['ops'] .= sprintf(
                "BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
                $f, $size, $x, $py, $this->esc($txt)
            );
        }

        public function line(float $x1, float $y1, float $x2, float $y2, float $wid = 0.7, ?float $r = null, ?float $g = null, ?float $b = null): void {
            $color = '';
            if ($r !== null && $g !== null && $b !== null) {
                $color = sprintf("%.3f %.3f %.3f RG\n", $r, $g, $b);
            }
            $this->pages[$this->currentPageIndex]['ops'] .= sprintf(
                "%s%.2f w %.2f %.2f m %.2f %.2f l S\n0 0 0 RG\n",
                $color, $wid, $x1, self::H - $y1, $x2, self::H - $y2
            );
        }

        public function rect(float $x, float $y, float $w, float $h, float $wid = 0.7, ?float $r = null, ?float $g = null, ?float $b = null): void {
            $color = '';
            if ($r !== null && $g !== null && $b !== null) {
                $color = sprintf("%.3f %.3f %.3f RG\n", $r, $g, $b);
            }
            $this->pages[$this->currentPageIndex]['ops'] .= sprintf(
                "%s%.2f w %.2f %.2f %.2f %.2f re S\n0 0 0 RG\n",
                $color, $wid, $x, self::H - $y - $h, $w, $h
            );
        }

        public function fillRect(float $x, float $y, float $w, float $h, float $r, float $g, float $b): void {
            $this->pages[$this->currentPageIndex]['ops'] .= sprintf(
                "%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f 0 0 0 rg\n",
                $r, $g, $b, $x, self::H - $y - $h, $w, $h
            );
        }

        public function roundedRect(float $x, float $y, float $w, float $h, float $rad, float $r, float $g, float $b, bool $fill = true, bool $stroke = false, float $borderR = 0, float $borderG = 0, float $borderB = 0, float $borderW = 0.7): void {
            $cy = self::H - $y;
            $k = 0.552284749831 * $rad;
            $ops = '';

            if ($fill) {
                $ops .= sprintf("%.3f %.3f %.3f rg\n", $r, $g, $b);
            }
            if ($stroke) {
                $ops .= sprintf("%.3f %.3f %.3f RG\n%.2f w\n", $borderR, $borderG, $borderB, $borderW);
            }

            $ops .= sprintf("%.2f %.2f m\n", $x + $rad, $cy);
            $ops .= sprintf("%.2f %.2f l\n", $x + $w - $rad, $cy);
            $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $w - $rad + $k, $cy, $x + $w, $cy - $rad + $k, $x + $w, $cy - $rad);
            $ops .= sprintf("%.2f %.2f l\n", $x + $w, $cy - $h + $rad);
            $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $w, $cy - $h + $rad - $k, $x + $w - $rad + $k, $cy - $h, $x + $w - $rad, $cy - $h);
            $ops .= sprintf("%.2f %.2f l\n", $x + $rad, $cy - $h);
            $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x + $rad - $k, $cy - $h, $x, $cy - $h + $rad - $k, $x, $cy - $h + $rad);
            $ops .= sprintf("%.2f %.2f l\n", $x, $cy - $rad);
            $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $x, $cy - $rad + $k, $x + $rad - $k, $cy, $x + $rad, $cy);

            if ($fill && $stroke) {
                $ops .= "B\n";
            } elseif ($fill) {
                $ops .= "f\n";
            } elseif ($stroke) {
                $ops .= "S\n";
            }
            $ops .= "0 0 0 rg 0 0 0 RG\n";

            $this->pages[$this->currentPageIndex]['ops'] .= $ops;
        }

        public function setTextColor(float $r, float $g, float $b): void {
            $this->pages[$this->currentPageIndex]['ops'] .= sprintf("%.3f %.3f %.3f rg\n", $r, $g, $b);
        }

        public function resetTextColor(): void {
            $this->pages[$this->currentPageIndex]['ops'] .= "0 0 0 rg\n";
        }

        public function image(string $jpegPath, float $x, float $y, float $w, float $h): bool {
            if (!$jpegPath || !is_readable($jpegPath)) return false;

            if (!isset($this->images[$jpegPath])) {
                $bytes = @file_get_contents($jpegPath);
                if (!$bytes || substr($bytes, 0, 2) !== "\xFF\xD8") return false;

                $i = 2; $pw = 0; $ph = 0; $n = strlen($bytes);
                while ($i < $n - 9) {
                    if (ord($bytes[$i]) !== 0xFF) { $i++; continue; }
                    $m = ord($bytes[$i + 1]);
                    if (in_array($m, [0xC0, 0xC1, 0xC2], true)) {
                        $ph = (ord($bytes[$i + 5]) << 8) + ord($bytes[$i + 6]);
                        $pw = (ord($bytes[$i + 7]) << 8) + ord($bytes[$i + 8]);
                        break;
                    }
                    if ($m === 0xD8 || ($m >= 0xD0 && $m <= 0xD9)) { $i += 2; continue; }
                    $len = (ord($bytes[$i + 2]) << 8) + ord($bytes[$i + 3]);
                    $i += 2 + $len;
                }
                if (!$pw || !$ph) return false;

                $alias = 'Im' . $this->nextImageId++;
                $this->images[$jpegPath] = [
                    'alias' => $alias,
                    'width' => $pw,
                    'height' => $ph,
                    'bytes' => $bytes
                ];
            }

            $img = $this->images[$jpegPath];
            $alias = $img['alias'];

            if (!in_array($alias, $this->pages[$this->currentPageIndex]['images'], true)) {
                $this->pages[$this->currentPageIndex]['images'][] = $alias;
            }

            $this->pages[$this->currentPageIndex]['ops'] .= sprintf(
                "q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
                $w, $h, $x, self::H - $y - $h, $alias
            );
            return true;
        }

        public function output(): string {
            $objs = [];
            $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";

            $kids = [];
            $fontConfigs = [
                'F1' => ['name' => 'GoogleSansFlex-Regular',  'fontObj' => 50, 'descObj' => 51, 'fileObj' => 52, 'widthObj' => 53],
                'F2' => ['name' => 'GoogleSansFlex-Medium',   'fontObj' => 60, 'descObj' => 61, 'fileObj' => 62, 'widthObj' => 63],
                'F3' => ['name' => 'GoogleSansFlex-SemiBold', 'fontObj' => 70, 'descObj' => 71, 'fileObj' => 72, 'widthObj' => 73],
                'F4' => ['name' => 'GoogleSansFlex-Bold',     'fontObj' => 80, 'descObj' => 81, 'fileObj' => 82, 'widthObj' => 83],
            ];

            $imageStartObj = 1000;
            $imageMap = [];
            foreach ($this->images as $path => $img) {
                $imageMap[$img['alias']] = $imageStartObj++;
            }

            $pageObjStart = 100;
            foreach ($this->pages as $idx => $p) {
                $pObjId = $pageObjStart + ($idx * 2);
                $cObjId = $pObjId + 1;
                $kids[] = "$pObjId 0 R";

                $resFonts = "/F1 50 0 R /F2 60 0 R /F3 70 0 R /F4 80 0 R";
                $resXObjs = "";
                if (!empty($p['images'])) {
                    $resXObjs .= "/XObject << ";
                    foreach ($p['images'] as $alias) {
                        $resXObjs .= "/$alias " . $imageMap[$alias] . " 0 R ";
                    }
                    $resXObjs .= ">>";
                }

                $objs[$pObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] "
                               . "/Resources << /Font << $resFonts >> $resXObjs /ProcSet [/PDF /Text /ImageC] >> "
                               . "/Contents $cObjId 0 R >>";

                $opsLen = strlen($p['ops']);
                $objs[$cObjId] = "<< /Length $opsLen >>\nstream\n" . $p['ops'] . "\nendstream";
            }

            $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";

            foreach ($fontConfigs as $alias => $cfg) {
                $fontPath = $this->fontPaths[$alias] ?? null;
                $fontInfo = $this->fonts[$alias] ?? null;

                if ($fontPath && file_exists($fontPath) && $fontInfo) {
                    $fontData = @file_get_contents($fontPath);
                    $fontLen = strlen($fontData ?: '');

                    $objs[$cfg['fileObj']] = "<< /Length $fontLen /Length1 $fontLen >>\nstream\n" . $fontData . "\nendstream";

                    $objs[$cfg['descObj']] = "<< /Type /FontDescriptor /FontName /" . $cfg['name'] . " /Flags 32 "
                                           . "/FontBBox [" . implode(' ', $fontInfo->bbox) . "] /ItalicAngle 0 "
                                           . "/Ascent " . $fontInfo->ascent . " /Descent " . $fontInfo->descent . " "
                                           . "/CapHeight " . $fontInfo->ascent . " /StemV 80 /FontFile2 " . $cfg['fileObj'] . " 0 R >>";

                    $objs[$cfg['fontObj']] = "<< /Type /Font /Subtype /TrueType /BaseFont /" . $cfg['name'] . " "
                                           . "/FirstChar 32 /LastChar 255 /Widths " . $cfg['widthObj'] . " 0 R "
                                           . "/FontDescriptor " . $cfg['descObj'] . " 0 R /Encoding /WinAnsiEncoding >>";

                    $widthsArr = [];
                    for ($c = 32; $c <= 255; $c++) {
                        $widthsArr[] = $fontInfo->widths[$c] ?? 500;
                    }
                    $objs[$cfg['widthObj']] = "[ " . implode(' ', $widthsArr) . " ]";
                } else {
                    $baseFont = ($alias === 'F4') ? 'Helvetica-Bold' : (($alias === 'F3' || $alias === 'F2') ? 'Helvetica-Bold' : 'Helvetica');
                    $objs[$cfg['fontObj']] = "<< /Type /Font /Subtype /Type1 /BaseFont /" . $baseFont . " /Encoding /WinAnsiEncoding >>";
                }
            }

            foreach ($this->images as $path => $img) {
                $alias = $img['alias'];
                $objId = $imageMap[$alias];
                $jb = $img['bytes'];
                $jw = $img['width'];
                $jh = $img['height'];
                $objs[$objId] = "<< /Type /XObject /Subtype /Image /Width $jw /Height $jh /ColorSpace /DeviceRGB"
                              . " /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jb) . " >>\nstream\n" . $jb . "\nendstream";
            }

            $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
            $offsets = [];
            foreach ($objs as $num => $body) {
                $offsets[$num] = strlen($pdf);
                $pdf .= "$num 0 obj\n$body\nendobj\n";
            }
            $xref = strlen($pdf);
            $max  = max(array_keys($objs));
            $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
            for ($i = 1; $i <= $max; $i++) {
                $pdf .= isset($offsets[$i])
                    ? sprintf("%010d 00000 n \n", $offsets[$i])
                    : "0000000000 65535 f \n";
            }
            $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
            return $pdf;
        }
    }
}

/**
 * Word wrap helper for PDF writer.
 * Returns array of wrapped lines.
 */
function mentor_report_wrap_text(MentorReportPDFWriter $pdf, string $text, float $fontSize, float $maxWidth, $weight = 400): array {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $paragraphs = explode("\n", $text);
    $lines = [];

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') {
            $lines[] = '';
            continue;
        }

        $words = preg_split('/\s+/', $para);
        $currLine = '';

        foreach ($words as $w) {
            $test = ($currLine === '') ? $w : $currLine . ' ' . $w;
            if ($pdf->width($test, $fontSize, $weight) > $maxWidth) {
                if ($currLine !== '') {
                    $lines[] = $currLine;
                    $currLine = $w;
                } else {
                    // Single long word
                    $lines[] = $w;
                    $currLine = '';
                }
            } else {
                $currLine = $test;
            }
        }
        if ($currLine !== '') {
            $lines[] = $currLine;
        }
    }
    return $lines;
}

/**
 * Main report renderer for Mentor Performance PDF.
 *
 * @param array $data Structured data array
 * @return string Raw PDF bytes
 */
function render_mentor_performance_report_pdf(array $data): string {
    $pdf = new MentorReportPDFWriter();

    $mentor       = $data['mentor'] ?? [];
    $stats        = $data['stats'] ?? [];
    $badge        = $data['badge'] ?? [];
    $filters      = $data['filters'] ?? [];
    $dailyTrend   = $data['daily_trend'] ?? [];
    $interactions = $data['interactions'] ?? [];

    $mentorName = !empty($mentor['full_name']) ? (string)$mentor['full_name'] : ((string)($mentor['username'] ?? 'Mentor'));
    $mentorUser = (string)($mentor['username'] ?? 'mentor');
    $mentorRole = ucfirst(str_replace('_', ' ', (string)($mentor['admin_type'] ?? $mentor['role'] ?? 'Mentor')));

    $lm = 36.0;
    $rm = MentorReportPDFWriter::W - 36.0;
    $usableW = $rm - $lm; // 523.28

    // Palette
    $cOrangeR = 255/255; $cOrangeG = 107/255; $cOrangeB = 0/255; // #ff6b00
    $cDarkR   = 15/255;  $cDarkG   = 23/255;  $cDarkB   = 42/255;  // #0f172a
    $cSlateR  = 30/255;  $cSlateG  = 41/255;  $cSlateB  = 59/255;  // #1e293b
    $cMutedR  = 100/255; $cMutedG  = 116/255; $cMutedB  = 139/255; // #64748b
    $cBgLightR= 248/255; $cBgLightG= 250/255; $cBgLightB= 252/255; // #f8fafc
    $cBorderR = 226/255; $cBorderG = 232/255; $cBorderB = 240/255; // #e2e8f0
    $cGreenR  = 16/255;  $cGreenG  = 185/255; $cGreenB  = 129/255; // #10b981
    $cBlueR   = 59/255;  $cBlueG   = 130/255; $cBlueB   = 246/255; // #3b82f6

    // Helper: Draw Header on Top of Page
    $drawPageHeader = function(int $pageNum) use ($pdf, $lm, $usableW, $cDarkR, $cDarkG, $cDarkB, $cOrangeR, $cOrangeG, $cOrangeB, $mentorName, $filters) {
        // Top branding strip
        $pdf->fillRect($lm, 24, $usableW, 4, $cOrangeR, $cOrangeG, $cOrangeB);

        // Header Title
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, 34, 13, 'PEPP LEARNING — MENTOR PERFORMANCE REPORT', 700);

        $pdf->setTextColor(100/255, 116/255, 139/255);
        $pdf->text($lm, 50, 7.5, 'Official Academic Mentoring & Productivity Audit Record', 400);

        // Right side metadata
        $genText = 'Generated: ' . ($filters['generated_at'] ?? date('d M Y, h:i A'));
        $pdf->text($lm, 36, 7.5, $genText, 500, 'R', $usableW);
        $pdf->text($lm, 48, 7.5, 'Confidential Administration Document', 400, 'R', $usableW);

        $pdf->line($lm, 62, $lm + $usableW, 62, 0.7, 226/255, 232/255, 240/255);
    };

    // Helper: Draw Footer on Bottom of Page
    $drawPageFooter = function(int $pageNum, int $totalEstimate) use ($pdf, $lm, $usableW, $cMutedR, $cMutedG, $cMutedB) {
        $pdf->line($lm, 805, $lm + $usableW, 805, 0.5, 226/255, 232/255, 240/255);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, 812, 7, 'PEPP Learning ERP • Strict Data Integrity & Mentoring Governance', 400);
        $pageStr = 'Page ' . $pageNum;
        $pdf->text($lm, 812, 7, $pageStr, 600, 'R', $usableW);
    };

    // PAGE 1: Header + Filter Block + Mentor Profile + KPI Summary + Trend Summary + Interactions (start)
    $drawPageHeader(1);

    $y = 70.0;

    // ── 1. Mentor Profile & Filter Context Card ─────────────────────────
    $cardH = 68.0;
    $pdf->roundedRect($lm, $y, $usableW, $cardH, 6, $cDarkR, $cDarkG, $cDarkB, true, false);

    // Avatar Circle Placeholder / Initials
    $pdf->roundedRect($lm + 12, $y + 12, 44, 44, 10, $cOrangeR, $cOrangeG, $cOrangeB, true, false);
    $initial = strtoupper(substr($mentorName, 0, 1));
    $pdf->setTextColor(1, 1, 1);
    $pdf->text($lm + 12, $y + 22, 16, $initial, 700, 'C', 44);

    // Mentor Identity Info
    $pdf->setTextColor(1, 1, 1);
    $pdf->text($lm + 66, $y + 14, 12, $mentorName, 700);

    $statusLabel = !empty($stats['is_online']) ? 'ONLINE' : 'OFFLINE';
    $statusColor = !empty($stats['is_online']) ? [34/255, 197/255, 94/255] : [148/255, 163/255, 184/255];
    $pdf->setTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
    $nameW = $pdf->width($mentorName, 12, 700);
    $pdf->text($lm + 72 + $nameW, $y + 16, 7.5, '[' . $statusLabel . ']', 700);

    $pdf->setTextColor(203/255, 213/255, 225/255);
    $meta1 = "Username: {$mentorUser}   |   Designation: {$mentorRole}   |   Last Active: " . ($stats['last_active_label'] ?? 'N/A');
    $pdf->text($lm + 66, $y + 30, 8, $meta1, 400);

    $meta2 = "Reporting Window: " . ($filters['global_range']['title'] ?? 'Standard Range') . "   |   Interaction Filter: " . ($filters['int_period']['title'] ?? 'Last 1 Day') . " (" . ucfirst($filters['int_type'] ?? 'All') . ")";
    $pdf->text($lm + 66, $y + 43, 7.5, $meta2, 400);

    $meta3 = "Search Filter: " . (!empty($filters['int_search']) ? '"' . $filters['int_search'] . '"' : 'None');
    $pdf->text($lm + 66, $y + 54, 7.5, $meta3, 500);

    // Right-hand Badge & Rank
    $badgeTitle = $badge['title'] ?? 'Developing Performer';
    $badgeRank  = 'Rank #' . ($badge['rank'] ?? 1) . ' of ' . ($badge['total_ranked'] ?? 1) . ' Active Mentors';
    $badgeScore = 'Score: ' . number_format((float)($badge['score'] ?? 0), 1);

    $pdf->roundedRect($lm + $usableW - 136, $y + 12, 124, 22, 5, $cSlateR, $cSlateG, $cSlateB, true, true, 71/255, 85/255, 105/255, 0.7);
    $pdf->setTextColor(251/255, 191/255, 36/255);
    $pdf->text($lm + $usableW - 136, $y + 18, 8, $badgeTitle, 700, 'C', 124);

    $pdf->setTextColor(203/255, 213/255, 225/255);
    $pdf->text($lm + $usableW - 136, $y + 38, 7.5, $badgeRank, 600, 'C', 124);
    $pdf->text($lm + $usableW - 136, $y + 49, 7.5, $badgeScore, 700, 'C', 124);

    $y += $cardH + 12;

    // ── 2. Executive KPI Metric Boxes (5 Cards Grid) ────────────────────
    $kpiCount = 5;
    $kpiGap   = 8.0;
    $kpiW     = ($usableW - ($kpiGap * ($kpiCount - 1))) / $kpiCount; // ~98pt each
    $kpiH     = 48.0;

    $kpis = [
        [
            'title' => 'ASSIGNED ACTIVE',
            'val'   => (string)($stats['assigned_students_count'] ?? 0),
            'sub'   => 'Active enrolled students',
            'r' => 245/255, 'g' => 243/255, 'b' => 255/255,
            'tr'=> 124/255, 'tg'=> 58/255,  'tb'=> 237/255
        ],
        [
            'title' => 'CALLS LOGGED',
            'val'   => (string)($stats['calls_count'] ?? 0),
            'sub'   => 'Calls in period',
            'r' => 239/255, 'g' => 246/255, 'b' => 255/255,
            'tr'=> 37/255,  'tg'=> 99/255,  'tb'=> 235/255
        ],
        [
            'title' => 'REMARKS ADDED',
            'val'   => (string)($stats['remarks_count'] ?? 0),
            'sub'   => 'Student follow-up notes',
            'r' => 255/255, 'g' => 247/255, 'b' => 237/255,
            'tr'=> 234/255, 'tg'=> 88/255,  'tb'=> 12/255
        ],
        [
            'title' => 'CONTACT RATE',
            'val'   => number_format((float)($stats['contact_rate'] ?? 0), 1) . '%',
            'sub'   => (int)($stats['unique_contacted_count'] ?? 0) . ' students reached',
            'r' => 236/255, 'g' => 253/255, 'b' => 245/255,
            'tr'=> 5/255,   'tg'=> 150/255, 'tb'=> 105/255
        ],
        [
            'title' => 'ACTIVE DAYS',
            'val'   => (string)($stats['active_days_count'] ?? 0),
            'sub'   => 'Streak: ' . ($stats['current_streak'] ?? 0) . ' day(s)',
            'r' => 254/255, 'g' => 252/255, 'b' => 232/255,
            'tr'=> 202/255, 'tg'=> 138/255, 'tb'=> 4/255
        ]
    ];

    foreach ($kpis as $kIdx => $k) {
        $kx = $lm + ($kIdx * ($kpiW + $kpiGap));
        $pdf->roundedRect($kx, $y, $kpiW, $kpiH, 4, $k['r'], $k['g'], $k['b'], true, true, $cBorderR, $cBorderG, $cBorderB, 0.5);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($kx + 6, $y + 6, 6.2, $k['title'], 700);

        $pdf->setTextColor($k['tr'], $k['tg'], $k['tb']);
        $pdf->text($kx + 6, $y + 16, 14, $k['val'], 700);

        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($kx + 6, $y + 36, 6.5, $k['sub'], 400);
    }

    $y += $kpiH + 12;

    // ── 3. Academic Engagement Summary Row ──────────────────────────────
    $secH = 28.0;
    $pdf->roundedRect($lm, $y, $usableW, $secH, 4, $cBgLightR, $cBgLightG, $cBgLightB, true, true, $cBorderR, $cBorderG, $cBorderB, 0.5);

    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $pdf->text($lm + 10, $y + 8, 8, 'Academic Workload & Engagement Indicators:', 700);

    $progVal = number_format((float)($stats['avg_student_progress'] ?? 0), 1) . '%';
    $attVal  = number_format((float)($stats['avg_student_attendance'] ?? 0), 1) . '%';
    $uncontactedVal = (int)($stats['uncontacted_count'] ?? 0);

    $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
    $pdf->text($lm + 10, $y + 18, 7.5, "Avg Study Plan Progress: ", 400);
    $pdf->setTextColor($cBlueR, $cBlueG, $cBlueB);
    $pdf->text($lm + 105, $y + 18, 7.5, $progVal, 700);

    $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
    $pdf->text($lm + 180, $y + 18, 7.5, "Avg Attendance: ", 400);
    $pdf->setTextColor(124/255, 58/255, 237/255);
    $pdf->text($lm + 250, $y + 18, 7.5, $attVal, 700);

    $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
    $pdf->text($lm + 320, $y + 18, 7.5, "Uncontacted Active Students: ", 400);
    $unColor = ($uncontactedVal > 0) ? [239/255, 68/255, 68/255] : [16/255, 185/255, 129/255];
    $pdf->setTextColor($unColor[0], $unColor[1], $unColor[2]);
    $pdf->text($lm + 435, $y + 18, 7.5, (string)$uncontactedVal, 700);

    $y += $secH + 12;

    // ── 4. Activity Trend Distribution Table / Breakdown ────────────────
    if (!empty($dailyTrend)) {
        $trendH = 14.0;
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
        $pdf->text($lm, $y, 9, 'Activity Trend Breakdown (' . ($filters['global_range']['title'] ?? 'Period') . '):', 700);
        $y += 12;

        // Show compact grid of trend items (up to 12 visible items per row)
        $numTrend = count($dailyTrend);
        $itemW = min(58.0, $usableW / max(1, $numTrend));
        $gridH = 26.0;

        $pdf->roundedRect($lm, $y, $usableW, $gridH, 3, 255/255, 255/255, 255/255, true, true, $cBorderR, $cBorderG, $cBorderB, 0.5);

        foreach (array_values($dailyTrend) as $tIdx => $tItem) {
            $tx = $lm + ($tIdx * $itemW);
            if ($tx + $itemW > $rm + 1) break;

            $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
            $pdf->text($tx, $y + 4, 6.2, $tItem['label'] ?? '', 600, 'C', $itemW);

            $callTxt = 'C: ' . ($tItem['calls'] ?? 0);
            $remTxt  = 'R: ' . ($tItem['remarks'] ?? 0);

            $pdf->setTextColor($cBlueR, $cBlueG, $cBlueB);
            $pdf->text($tx, $y + 12, 6.2, $callTxt, 700, 'C', $itemW);

            $pdf->setTextColor($cOrangeR, $cOrangeG, $cOrangeB);
            $pdf->text($tx, $y + 19, 6.2, $remTxt, 700, 'C', $itemW);

            if ($tIdx > 0) {
                $pdf->line($tx, $y, $tx, $y + $gridH, 0.4, $cBorderR, $cBorderG, $cBorderB);
            }
        }
        $y += $gridH + 14;
    }

    // ── 5. Interaction History Table Header ─────────────────────────────
    $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
    $intSectionTitle = 'Interaction History (Calls & Remarks) — ' . ($filters['int_period']['title'] ?? 'Last 1 Day');
    $pdf->text($lm, $y, 9.5, $intSectionTitle, 700);

    $intCountStr = count($interactions) . ' interaction(s) found';
    $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
    $pdf->text($lm, $y + 1, 7.5, $intCountStr, 500, 'R', $usableW);

    $y += 12;

    // Table Column Dimensions
    // Usable width: 523.28
    // Columns: # (20), Type (38), Date & Time (86), Student (105), Course (95), Notes & Remarks (179.28)
    $colW = [
        'num'     => 20.0,
        'type'    => 38.0,
        'time'    => 86.0,
        'student' => 105.0,
        'course'  => 95.0,
        'notes'   => 179.28
    ];

    $drawTableHeader = function(float $topY) use ($pdf, $lm, $usableW, $colW, $cDarkR, $cDarkG, $cDarkB) {
        $pdf->roundedRect($lm, $topY, $usableW, 16, 2, 241/255, 245/255, 249/255, true, true, 203/255, 213/255, 225/255, 0.5);
        $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);

        $curX = $lm;
        $pdf->text($curX + 4, $topY + 4.5, 6.8, '#', 700);
        $curX += $colW['num'];

        $pdf->text($curX + 4, $topY + 4.5, 6.8, 'TYPE', 700);
        $curX += $colW['type'];

        $pdf->text($curX + 4, $topY + 4.5, 6.8, 'DATE & TIME', 700);
        $curX += $colW['time'];

        $pdf->text($curX + 4, $topY + 4.5, 6.8, 'STUDENT', 700);
        $curX += $colW['student'];

        $pdf->text($curX + 4, $topY + 4.5, 6.8, 'COURSE', 700);
        $curX += $colW['course'];

        $pdf->text($curX + 4, $topY + 4.5, 6.8, 'NOTES & REMARKS', 700);
    };

    $drawTableHeader($y);
    $y += 16;

    if (empty($interactions)) {
        $pdf->roundedRect($lm, $y, $usableW, 36, 3, $cBgLightR, $cBgLightG, $cBgLightB, true, true, $cBorderR, $cBorderG, $cBorderB, 0.5);
        $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
        $pdf->text($lm, $y + 14, 8, 'No interactions found matching the selected filters for this mentor.', 500, 'C', $usableW);
        $y += 36;
    } else {
        $currentPage = 1;
        $maxY = 780.0;

        foreach ($interactions as $rowIdx => $int) {
            $numStr = (string)($rowIdx + 1);
            $typeStr = strtoupper((string)($int['type'] ?? 'CALL'));
            $isCall = ($typeStr === 'CALL');
            $timeStr = date('d M Y, h:i A', strtotime((string)($int['event_time'] ?? 'now')));
            $stName = (string)($int['student_name'] ?: ($int['student_user_id'] ?? '-'));
            $stId   = (string)($int['student_user_id'] ?? '');
            $course = (string)($int['pepp_course'] ?? '-');
            $note   = trim((string)($int['note'] ?? '-'));

            // Calculate heights for text wrapping
            $wrappedNoteLines = mentor_report_wrap_text($pdf, $note, 6.8, $colW['notes'] - 8, 400);
            if (empty($wrappedNoteLines)) $wrappedNoteLines = ['-'];

            $wrappedCourseLines = mentor_report_wrap_text($pdf, $course, 6.4, $colW['course'] - 8, 400);
            if (empty($wrappedCourseLines)) $wrappedCourseLines = ['-'];

            $wrappedStudentLines = mentor_report_wrap_text($pdf, $stName, 6.8, $colW['student'] - 8, 700);
            if (empty($wrappedStudentLines)) $wrappedStudentLines = ['-'];

            $lineCountNotes   = count($wrappedNoteLines);
            $lineCountCourse  = count($wrappedCourseLines);
            $lineCountStudent = count($wrappedStudentLines) + ($stId !== '' && $stId !== $stName ? 1 : 0);

            $maxLines = max($lineCountNotes, $lineCountCourse, $lineCountStudent);
            $rowH = max(20.0, ($maxLines * 8.8) + 6.0);

            // Page break check
            if ($y + $rowH > $maxY) {
                $drawPageFooter($currentPage, 0);

                $pdf->addPage();
                $currentPage++;
                $drawPageHeader($currentPage);

                $y = 70.0;
                $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
                $pdf->text($lm, $y, 8.5, $intSectionTitle . ' (Continued)', 700);
                $y += 10;

                $drawTableHeader($y);
                $y += 16;
            }

            // Alternating Row Background
            $bgRow = ($rowIdx % 2 === 1) ? [248/255, 250/255, 252/255] : [255/255, 255/255, 255/255];
            $pdf->fillRect($lm, $y, $usableW, $rowH, $bgRow[0], $bgRow[1], $bgRow[2]);
            $pdf->line($lm, $y + $rowH, $lm + $usableW, $y + $rowH, 0.4, $cBorderR, $cBorderG, $cBorderB);

            $curX = $lm;

            // #
            $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
            $pdf->text($curX + 4, $y + 5, 6.5, $numStr, 400);
            $curX += $colW['num'];

            // Type Badge
            if ($isCall) {
                $pdf->roundedRect($curX + 2, $y + 3.5, 32, 11, 2, 239/255, 246/255, 255/255, true, true, 191/255, 219/255, 254/255, 0.4);
                $pdf->setTextColor(29/255, 78/255, 216/255);
                $pdf->text($curX + 2, $y + 5, 6, 'CALL', 700, 'C', 32);
            } else {
                $pdf->roundedRect($curX + 2, $y + 3.5, 32, 11, 2, 255/255, 247/255, 237/255, true, true, 254/255, 215/255, 170/255, 0.4);
                $pdf->setTextColor(194/255, 65/255, 12/255);
                $pdf->text($curX + 2, $y + 5, 6, 'REMARK', 700, 'C', 32);
            }
            $curX += $colW['type'];

            // Date & Time
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $pdf->text($curX + 4, $y + 5, 6.8, $timeStr, 400);
            $curX += $colW['time'];

            // Student (Name + ID)
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $stY = $y + 4.5;
            foreach ($wrappedStudentLines as $sLine) {
                $pdf->text($curX + 4, $stY, 6.8, $sLine, 700);
                $stY += 8.2;
            }
            if ($stId !== '' && $stId !== $stName) {
                $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
                $pdf->text($curX + 4, $stY, 6.0, $stId, 400);
            }
            $curX += $colW['student'];

            // Course
            $pdf->setTextColor($cMutedR, $cMutedG, $cMutedB);
            $crsY = $y + 4.5;
            foreach ($wrappedCourseLines as $cLine) {
                $pdf->text($curX + 4, $crsY, 6.4, $cLine, 400);
                $crsY += 8.2;
            }
            $curX += $colW['course'];

            // Notes / Remarks Lines
            $pdf->setTextColor($cDarkR, $cDarkG, $cDarkB);
            $noteY = $y + 4.5;
            foreach ($wrappedNoteLines as $nLine) {
                $pdf->text($curX + 4, $noteY, 6.8, $nLine, 400);
                $noteY += 8.5;
            }

            $y += $rowH;
        }
    }

    // Final page footer for all generated pages
    $totalPages = $pdf->getPageCount();
    for ($p = 0; $p < $totalPages; $p++) {
        $pdf->setPage($p);
        $drawPageFooter($p + 1, $totalPages);
    }

    return $pdf->output();
}
