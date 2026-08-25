<?php
/**
 * PEPP Learning ERP — Download Merged Rank List PDF Endpoint.
 * Generates an official, print-quality A4 Portrait PDF.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'includes/auth.php';
require_once 'config/database.php';

// Verify admin permissions
if (!can_access('cards')) {
    http_response_code(403);
    exit('Access denied. You do not have permission to view result cards.');
}

$year = trim($_GET['year'] ?? '');
$plan_id = (int)($_GET['plan_id'] ?? 0);
$activity_id = (int)($_GET['activity_id'] ?? 0);

if (empty($year) || $plan_id <= 0 || $activity_id <= 0) {
    http_response_code(400);
    exit('Missing required parameters.');
}

// ── Dependency-Free Multi-Page PDF Writer ─────────────────────────────
class TTFFontInfo {
    public $unitsPerEm = 1000;
    public $ascent = 800;
    public $descent = -200;
    public $widths = [];
    public $bbox = [-1000, -1000, 1000, 1000];

    public function __construct($path) {
        $data = @file_get_contents($path);
        if (!$data) return;
        $this->parse($data);
    }

    private function parse($data) {
        $numTables = $this->unpackWord($data, 4);
        $tables = [];
        $offset = 12;
        for ($i = 0; $i < $numTables; $i++) {
            $tag = substr($data, $offset, 4);
            $tableOffset = $this->unpackDWord($data, $offset + 8);
            $length = $this->unpackDWord($data, $offset + 12);
            $tables[$tag] = ['offset' => $tableOffset, 'length' => $length];
            $offset += 16;
        }

        if (isset($tables['head'])) {
            $headOffset = $tables['head']['offset'];
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

        $numberOfHMetrics = 0;
        if (isset($tables['hhea'])) {
            $hheaOffset = $tables['hhea']['offset'];
            $asc = $this->unpackShort($data, $hheaOffset + 4);
            $desc = $this->unpackShort($data, $hheaOffset + 6);
            $this->ascent = round(($asc / $this->unitsPerEm) * 1000);
            $this->descent = round(($desc / $this->unitsPerEm) * 1000);
            $numberOfHMetrics = $this->unpackWord($data, $hheaOffset + 34);
        }

        $winAnsiToUnicode = [];
        for ($c = 32; $c <= 255; $c++) {
            if ($c >= 32 && $c <= 126) {
                $winAnsiToUnicode[$c] = $c;
            } else {
                $winAnsiToUnicode[$c] = self::$winAnsiMap[$c] ?? 0;
            }
        }

        $glyphMap = [];
        if (isset($tables['cmap'])) {
            $cmapOffset = $tables['cmap']['offset'];
            $numSubtables = $this->unpackWord($data, $cmapOffset + 2);
            $subtableOffset = 0;
            for ($i = 0; $i < $numSubtables; $i++) {
                $platformId = $this->unpackWord($data, $cmapOffset + 4 + $i * 8);
                $encodingId = $this->unpackWord($data, $cmapOffset + 6 + $i * 8);
                $offsetVal = $this->unpackDWord($data, $cmapOffset + 8 + $i * 8);
                if (($platformId == 3 && $encodingId == 1) || $platformId == 0) {
                    $subtableOffset = $cmapOffset + $offsetVal;
                    break;
                }
            }

            if ($subtableOffset > 0) {
                $format = $this->unpackWord($data, $subtableOffset);
                if ($format == 4) {
                    $segCount2 = $this->unpackWord($data, $subtableOffset + 6);
                    $segCount = $segCount2 / 2;
                    $endCodesOffset = $subtableOffset + 14;
                    $startCodesOffset = $endCodesOffset + $segCount2 + 2;
                    $idDeltasOffset = $startCodesOffset + $segCount2;
                    $idRangeOffsetsOffset = $idDeltasOffset + $segCount2;

                    foreach ($winAnsiToUnicode as $c => $unicode) {
                        if ($unicode == 0) {
                            $glyphMap[$c] = 0;
                            continue;
                        }
                        $glyphMap[$c] = 0;
                        for ($seg = 0; $seg < $segCount; $seg++) {
                            $endCode = $this->unpackWord($data, $endCodesOffset + $seg * 2);
                            if ($unicode <= $endCode) {
                                $startCode = $this->unpackWord($data, $startCodesOffset + $seg * 2);
                                if ($unicode >= $startCode) {
                                    $idDelta = $this->unpackShort($data, $idDeltasOffset + $seg * 2);
                                    $idRangeOffset = $this->unpackWord($data, $idRangeOffsetsOffset + $seg * 2);
                                    if ($idRangeOffset > 0) {
                                        $glyphOffset = $idRangeOffsetsOffset + $seg * 2 + $idRangeOffset + ($unicode - $startCode) * 2;
                                        $glyphIndex = $this->unpackWord($data, $glyphOffset);
                                        if ($glyphIndex > 0) {
                                            $glyphIndex = ($glyphIndex + $idDelta) & 0xFFFF;
                                        }
                                    } else {
                                        $glyphIndex = ($unicode + $idDelta) & 0xFFFF;
                                    }
                                    $glyphMap[$c] = $glyphIndex;
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (isset($tables['hmtx']) && $numberOfHMetrics > 0) {
            $hmtxOffset = $tables['hmtx']['offset'];
            foreach ($winAnsiToUnicode as $c => $unicode) {
                $glyphIndex = $glyphMap[$c] ?? 0;
                if ($glyphIndex < $numberOfHMetrics) {
                    $width = $this->unpackWord($data, $hmtxOffset + $glyphIndex * 4);
                } else {
                    $width = $this->unpackWord($data, $hmtxOffset + ($numberOfHMetrics - 1) * 4);
                }
                $this->widths[$c] = round(($width / $this->unitsPerEm) * 1000);
            }
        } else {
            for ($c = 32; $c <= 255; $c++) {
                $this->widths[$c] = 500;
            }
        }
    }

    private function unpackWord($data, $offset) {
        $arr = unpack('n', substr($data, $offset, 2));
        return $arr[1];
    }

    private function unpackShort($data, $offset) {
        $v = $this->unpackWord($data, $offset);
        return ($v >= 0x8000) ? $v - 0x10000 : $v;
    }

    private function unpackDWord($data, $offset) {
        $arr = unpack('N', substr($data, $offset, 4));
        return $arr[1];
    }

    private static $winAnsiMap = [
        127 => 0, 128 => 8364, 130 => 8218, 131 => 402, 132 => 8222, 133 => 8230, 134 => 8224, 135 => 8225,
        136 => 710, 137 => 8240, 138 => 352, 139 => 8249, 140 => 338, 142 => 381, 145 => 8216, 146 => 8217,
        147 => 8220, 148 => 8221, 149 => 8226, 150 => 8211, 151 => 8212, 152 => 732, 153 => 8482, 154 => 353,
        155 => 8250, 156 => 339, 158 => 382, 159 => 376, 160 => 160, 161 => 161, 162 => 162, 163 => 163,
        164 => 164, 165 => 165, 166 => 166, 167 => 167, 168 => 168, 169 => 169, 170 => 170, 171 => 171,
        172 => 172, 173 => 173, 174 => 174, 175 => 175, 176 => 176, 177 => 177, 178 => 178, 179 => 179,
        180 => 180, 181 => 181, 182 => 182, 183 => 183, 184 => 184, 185 => 185, 186 => 186, 187 => 187,
        188 => 188, 189 => 189, 190 => 190, 191 => 191, 192 => 192, 193 => 193, 194 => 194, 195 => 195,
        196 => 196, 197 => 197, 198 => 198, 199 => 199, 200 => 200, 201 => 201, 202 => 202, 203 => 203,
        204 => 204, 205 => 205, 206 => 206, 207 => 207, 208 => 208, 209 => 209, 210 => 210, 211 => 211,
        212 => 212, 213 => 213, 214 => 214, 215 => 215, 216 => 216, 217 => 217, 218 => 218, 219 => 219,
        220 => 220, 221 => 221, 222 => 222, 223 => 223, 224 => 224, 225 => 225, 226 => 226, 227 => 227,
        228 => 228, 229 => 229, 230 => 230, 231 => 231, 232 => 232, 233 => 233, 234 => 234, 235 => 235,
        236 => 236, 237 => 237, 238 => 238, 239 => 239, 240 => 240, 241 => 241, 242 => 242, 243 => 243,
        244 => 244, 245 => 245, 246 => 246, 247 => 247, 248 => 248, 249 => 249, 250 => 250, 251 => 251,
        252 => 252, 253 => 253, 254 => 254, 255 => 255
    ];
}

class MultiPagePDF {
    const W = 595.28; // A4 width in points
    const H = 841.89; // A4 height in points

    private $pages = [];
    private $currentPageIndex = -1;
    private $images = []; // filepath -> [alias, width, height, bytes]
    private $nextImageId = 1;
    private $fonts = [];

    public function __construct() {
        $this->addPage();

        $base_dir = __DIR__;
        if (!file_exists($base_dir . '/assets/fonts/GoogleSansFlex-Regular.ttf')) {
            $base_dir = 'd:/LABINC PVT LTD/PEPP Learning/PEPP/2026-27/Website 2027/Admin-Register-Installment/Antigravity/admissions';
        }
        $regularPath = $base_dir . '/assets/fonts/GoogleSansFlex-Regular.ttf';
        $mediumPath  = $base_dir . '/assets/fonts/GoogleSansFlex-Medium.ttf';
        $semiBoldPath = $base_dir . '/assets/fonts/GoogleSansFlex-SemiBold.ttf';
        $boldPath    = $base_dir . '/assets/fonts/GoogleSansFlex-Bold.ttf';

        global $pdo;
        if (isset($pdo)) {
            try {
                $db_fonts = $pdo->query("SELECT font_name, font_file FROM custom_fonts")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($db_fonts as $db_f) {
                    $name = strtolower($db_f['font_name']);
                    $file_path = __DIR__ . '/../' . $db_f['font_file'];
                    if (file_exists($file_path)) {
                        if (strpos($name, 'regular') !== false || (strpos($name, 'flex') !== false && strpos($name, 'bold') === false && strpos($name, 'medium') === false && strpos($name, 'semibold') === false)) {
                            $regularPath = $file_path;
                        } elseif (strpos($name, 'medium') !== false) {
                            $mediumPath = $file_path;
                        } elseif (strpos($name, 'semibold') !== false) {
                            $semiBoldPath = $file_path;
                        } elseif (strpos($name, 'bold') !== false) {
                            $boldPath = $file_path;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        $this->fonts['F1'] = new TTFFontInfo($regularPath);
        $this->fonts['F2'] = new TTFFontInfo($mediumPath);
        $this->fonts['F3'] = new TTFFontInfo($semiBoldPath);
        $this->fonts['F4'] = new TTFFontInfo($boldPath);
    }

    public function addPage() {
        $this->pages[] = [
            'ops' => '',
            'images' => [] // image aliases used on this page
        ];
        $this->currentPageIndex = count($this->pages) - 1;
    }

    private function esc($s) {
        $s = (string)$s;
        $s = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        if ($s === false) $s = '';
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    public function width($txt, $size, $weight = 400) {
        if ($weight === true) {
            $weight = 700;
        } elseif ($weight === false || $weight === null) {
            $weight = 400;
        }

        $f = 'F1';
        if ($weight == 500) {
            $f = 'F2';
        } elseif ($weight == 600) {
            $f = 'F3';
        } elseif ($weight >= 700) {
            $f = 'F4';
        }

        $font = $this->fonts[$f] ?? null;
        $totalWidth = 0;
        $len = strlen($txt);
        for ($i = 0; $i < $len; $i++) {
            $char = ord($txt[$i]);
            $w = ($font && isset($font->widths[$char])) ? $font->widths[$char] : 500;
            $totalWidth += $w;
        }
        return ($totalWidth / 1000) * $size;
    }

    public function text($x, $y, $size, $txt, $weight = 400, $align = 'L', $w = 0) {
        $tw = $this->width($txt, $size, $weight);
        if ($align === 'C') $x += max(0, ($w - $tw) / 2);
        if ($align === 'R') $x += max(0, $w - $tw);
        $py = self::H - $y - $size * 0.78;

        if ($weight === true) {
            $weight = 700;
        } elseif ($weight === false || $weight === null) {
            $weight = 400;
        }

        $f = 'F1';
        if ($weight == 500) {
            $f = 'F2';
        } elseif ($weight == 600) {
            $f = 'F3';
        } elseif ($weight >= 700) {
            $f = 'F4';
        }

        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $f, $size, $x, $py, $this->esc($txt));
    }

    public function line($x1, $y1, $x2, $y2, $wid = 0.7, $r = null, $g = null, $b = null) {
        $color = '';
        if ($r !== null && $g !== null && $b !== null) {
            $color = sprintf("%.3f %.3f %.3f RG\n", $r, $g, $b);
        }
        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("%s%.2f w %.2f %.2f m %.2f %.2f l S\n0 0 0 RG\n", $color, $wid, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function rect($x, $y, $w, $h, $wid = 0.7, $r = null, $g = null, $b = null) {
        $color = '';
        if ($r !== null && $g !== null && $b !== null) {
            $color = sprintf("%.3f %.3f %.3f RG\n", $r, $g, $b);
        }
        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("%s%.2f w %.2f %.2f %.2f %.2f re S\n0 0 0 RG\n", $color, $wid, $x, self::H - $y - $h, $w, $h);
    }

    public function fillRect($x, $y, $w, $h, $r, $g, $b) {
        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n0 0 0 rg\n", $r, $g, $b, $x, self::H - $y - $h, $w, $h);
    }

    public function setTextColor($r, $g, $b) {
        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("%.3f %.3f %.3f rg\n", $r, $g, $b);
    }

    public function setDash($array = [], $phase = 0) {
        if (empty($array)) {
            $this->pages[$this->currentPageIndex]['ops'] .= "[] 0 d\n";
        } else {
            $this->pages[$this->currentPageIndex]['ops'] .= "[" . implode(' ', $array) . "] $phase d\n";
        }
    }

    public function circle($x, $y, $r, $wid = 0.7, $fill = false, $red = null, $green = null, $blue = null) {
        $cx = $x;
        $cy = self::H - $y;
        $c = 0.552284749831 * $r;

        $ops = '';
        if ($fill && $red !== null && $green !== null && $blue !== null) {
            $ops .= sprintf("%.3f %.3f %.3f rg\n", $red, $green, $blue);
        } elseif ($red !== null && $green !== null && $blue !== null) {
            $ops .= sprintf("%.3f %.3f %.3f RG\n", $red, $green, $blue);
        }

        $ops .= sprintf("%.2f w\n", $wid);
        $ops .= sprintf("%.2f %.2f m\n", $cx + $r, $cy);
        $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx + $r, $cy + $c, $cx + $c, $cy + $r, $cx, $cy + $r);
        $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx - $c, $cy + $r, $cx - $r, $cy + $c, $cx - $r, $cy);
        $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx - $r, $cy - $c, $cx - $c, $cy - $r, $cx, $cy - $r);
        $ops .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx + $c, $cy - $r, $cx + $r, $cy - $c, $cx + $r, $cy);

        if ($fill) {
            $ops .= "f\n0 0 0 rg\n";
        } else {
            $ops .= "s\n0 0 0 RG\n";
        }
        $this->pages[$this->currentPageIndex]['ops'] .= $ops;
    }

    public function image($jpegPath, $x, $y, $w, $h) {
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

        if (!in_array($alias, $this->pages[$this->currentPageIndex]['images'])) {
            $this->pages[$this->currentPageIndex]['images'][] = $alias;
        }

        $this->pages[$this->currentPageIndex]['ops'] .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $alias);
        return true;
    }

    public function clipCircleImage($jpegPath, $cx, $cy, $r) {
        if (!$this->image($jpegPath, $cx - $r, $cy - $r, $r * 2, $r * 2)) {
            // Draw slate placeholder avatar dynamically if file is missing/malformed
            $this->circle($cx, $cy, $r, 0.7, true, 226/255, 232/255, 240/255); // Background
            $this->circle($cx, $cy, $r, 0.7, false, 203/255, 213/255, 225/255); // Border
            // Draw head & shoulder vector curves inside
            $this->circle($cx, $cy - $r * 0.1, $r * 0.35, 0.7, true, 148/255, 163/255, 184/255);
            return false;
        }

        $img = $this->images[$jpegPath];
        $alias = $img['alias'];

        // Remove standard non-clipped image operators
        $standard_op = sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $r * 2, $r * 2, $cx - $r, self::H - ($cy - $r) - ($r * 2), $alias);
        $len = strlen($standard_op);

        $ops = &$this->pages[$this->currentPageIndex]['ops'];
        if (substr($ops, -$len) === $standard_op) {
            $ops = substr($ops, 0, -$len);
        }

        $cy_pdf = self::H - $cy;
        $c = 0.552284749831 * $r;

        $clip_op = "q\n";
        $clip_op .= sprintf("%.2f %.2f m\n", $cx + $r, $cy_pdf);
        $clip_op .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx + $r, $cy_pdf + $c, $cx + $c, $cy_pdf + $r, $cx, $cy_pdf + $r);
        $clip_op .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx - $c, $cy_pdf + $r, $cx - $r, $cy_pdf + $c, $cx - $r, $cy_pdf);
        $clip_op .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx - $r, $cy_pdf - $c, $cx - $c, $cy_pdf - $r, $cx, $cy_pdf - $r);
        $clip_op .= sprintf("%.2f %.2f %.2f %.2f %.2f %.2f c\n", $cx + $c, $cy_pdf - $r, $cx + $r, $cy_pdf - $c, $cx + $r, $cy_pdf);
        $clip_op .= "W n\n";

        $clip_op .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $r * 2, $r * 2, $cx - $r, $cy_pdf - $r, $alias);
        $clip_op .= "Q\n";

        $ops .= $clip_op;
        return true;
    }

    public function output($pdo = null, $year = '') {
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];

        $base_dir = __DIR__;
        if (!file_exists($base_dir . '/assets/fonts/GoogleSansFlex-Regular.ttf')) {
            $base_dir = 'd:/LABINC PVT LTD/PEPP Learning/PEPP/2026-27/Website 2027/Admin-Register-Installment/Antigravity/admissions';
        }
        $regularPath = $base_dir . '/assets/fonts/GoogleSansFlex-Regular.ttf';
        $mediumPath  = $base_dir . '/assets/fonts/GoogleSansFlex-Medium.ttf';
        $semiBoldPath = $base_dir . '/assets/fonts/GoogleSansFlex-SemiBold.ttf';
        $boldPath    = $base_dir . '/assets/fonts/GoogleSansFlex-Bold.ttf';

        if ($pdo) {
            try {
                $db_fonts = $pdo->query("SELECT font_name, font_file FROM custom_fonts")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($db_fonts as $db_f) {
                    $name = strtolower($db_f['font_name']);
                    $file_path = __DIR__ . '/../' . $db_f['font_file'];
                    if (file_exists($file_path)) {
                        if (strpos($name, 'regular') !== false || (strpos($name, 'flex') !== false && strpos($name, 'bold') === false && strpos($name, 'medium') === false && strpos($name, 'semibold') === false)) {
                            $regularPath = $file_path;
                        } elseif (strpos($name, 'medium') !== false) {
                            $mediumPath = $file_path;
                        } elseif (strpos($name, 'semibold') !== false) {
                            $semiBoldPath = $file_path;
                        } elseif (strpos($name, 'bold') !== false) {
                            $boldPath = $file_path;
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        $fontConfigs = [
            'F1' => ['name' => 'GoogleSansFlex-Regular', 'path' => $regularPath, 'fontObj' => 50, 'descObj' => 51, 'fileObj' => 52, 'widthObj' => 53],
            'F2' => ['name' => 'GoogleSansFlex-Medium', 'path' => $mediumPath, 'fontObj' => 60, 'descObj' => 61, 'fileObj' => 62, 'widthObj' => 63],
            'F3' => ['name' => 'GoogleSansFlex-SemiBold', 'path' => $semiBoldPath, 'fontObj' => 70, 'descObj' => 71, 'fileObj' => 72, 'widthObj' => 73],
            'F4' => ['name' => 'GoogleSansFlex-Bold', 'path' => $boldPath, 'fontObj' => 80, 'descObj' => 81, 'fileObj' => 82, 'widthObj' => 83],
        ];

        $imageStartObj = 1000;
        $imageMap = [];
        $imgIndex = $imageStartObj;
        foreach ($this->images as $path => $img) {
            $imageMap[$img['alias']] = $imgIndex++;
        }

        foreach ($this->pages as $i => $page) {
            $pageObjId = 100 + 2 * $i;
            $contentsObjId = 101 + 2 * $i;
            $kids[] = "$pageObjId 0 R";

            $res = "/Font << ";
            foreach ($fontConfigs as $alias => $cfg) {
                $res .= "/$alias " . $cfg['fontObj'] . " 0 R ";
            }
            $res .= " >>";

            if (!empty($page['images'])) {
                $res .= " /XObject << ";
                foreach ($page['images'] as $alias) {
                    $res .= "/$alias " . $imageMap[$alias] . " 0 R ";
                }
                $res .= " >>";
            }

            $objs[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] /Resources << $res >> /Contents $contentsObjId 0 R >>";
            $stream = $page['ops'];
            $objs[$contentsObjId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($this->pages) . " >>";

        foreach ($fontConfigs as $alias => $cfg) {
            $ttfBytes = @file_get_contents($cfg['path']);
            if (!$ttfBytes) {
                $ttfBytes = @file_get_contents($fontConfigs['F1']['path']);
            }

            $fontInfo = $this->fonts[$alias] ?? null;
            $widthsStr = "[";
            for ($c = 32; $c <= 255; $c++) {
                $w = ($fontInfo && isset($fontInfo->widths[$c])) ? $fontInfo->widths[$c] : 500;
                $widthsStr .= $w . " ";
            }
            $widthsStr = rtrim($widthsStr) . "]";

            if ($ttfBytes && $fontInfo) {
                $objs[$cfg['fontObj']] = "<< /Type /Font /Subtype /TrueType /BaseFont /" . $cfg['name']
                                       . " /FirstChar 32 /LastChar 255 /Widths " . $cfg['widthObj'] . " 0 R"
                                       . " /FontDescriptor " . $cfg['descObj'] . " 0 R /Encoding /WinAnsiEncoding >>";

                $objs[$cfg['descObj']] = "<< /Type /FontDescriptor /FontName /" . $cfg['name']
                                       . " /Flags 32 /FontBBox [" . implode(' ', $fontInfo->bbox) . "]"
                                       . " /ItalicAngle 0 /Ascent " . $fontInfo->ascent
                                       . " /Descent " . $fontInfo->descent
                                       . " /CapHeight 700 /StemV 80"
                                       . " /FontFile2 " . $cfg['fileObj'] . " 0 R >>";

                $objs[$cfg['fileObj']] = "<< /Length " . strlen($ttfBytes) . " /Length1 " . strlen($ttfBytes) . " >>\nstream\n" . $ttfBytes . "\nendstream";

                $objs[$cfg['widthObj']] = $widthsStr;
            } else {
                $baseFont = 'Helvetica';
                if ($alias === 'F4') $baseFont = 'Helvetica-Bold';
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

// ── Database Queries ──────────────────────────────────────────────────
$batch_ids = [];
try {
    $stmt_batches = $pdo->prepare("
        SELECT id, course_id FROM assessment_result_batches
        WHERE activity_id = ?
          AND study_plan_id = ?
          AND academic_year = ?
          AND status = 'published'
    ");
    $stmt_batches->execute([$activity_id, $plan_id, $year]);
    $batches = $stmt_batches->fetchAll(PDO::FETCH_ASSOC);
    $batch_ids = array_column($batches, 'id');
} catch (Exception $e) {
    http_response_code(500);
    exit('Database query failed: ' . $e->getMessage());
}

if (empty($batch_ids)) {
    http_response_code(404);
    exit('No published assessment result batches found for the selected test activity.');
}

// Fetch and format course name batch
$course_name = '';
if (!empty($batches)) {
    if ((int)$batches[0]['course_id'] === 0) {
        try {
            $stmt_plan = $pdo->prepare("SELECT title FROM study_plans WHERE id = ?");
            $stmt_plan->execute([$plan_id]);
            $plan_title = $stmt_plan->fetchColumn();
            if ($plan_title) {
                $course_name = $plan_title;
            }
        } catch (Exception $e) {}
    } else {
        try {
            $stmt_course = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
            $stmt_course->execute([$batches[0]['course_id']]);
            $raw_course = $stmt_course->fetchColumn();
            if ($raw_course) {
                $course_name = trim(preg_replace('/\s*\([^)]+\)/', '', $raw_course));
            }
        } catch (Exception $e) {}
    }
}

// Load test details from study_plan_activities or fallback snapshot
$chapter_name = '';
$test_date_raw = '';

try {
    $stmt = $pdo->prepare("SELECT * FROM study_plan_activities WHERE id = ?");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch();
    if ($activity) {
        $chapter_name = $activity['chapter'];
        $test_date_raw = $activity['activity_date'];
    }
} catch (Exception $e) {}

if (empty($chapter_name) || empty($test_date_raw)) {
    try {
        $stmt_snap = $pdo->prepare("
            SELECT activity_date_snapshot, chapter_snapshot
            FROM assessment_result_batches
            WHERE activity_id = ? AND status = 'published'
            LIMIT 1
        ");
        $stmt_snap->execute([$activity_id]);
        $snap = $stmt_snap->fetch();
        if ($snap) {
            if (empty($chapter_name)) $chapter_name = $snap['chapter_snapshot'];
            if (empty($test_date_raw)) $test_date_raw = $snap['activity_date_snapshot'];
        }
    } catch (Exception $e) {}
}

if (empty($chapter_name)) {
    $chapter_name = 'Sensation and Perception';
}
$formatted_date = !empty($test_date_raw) ? date('j F Y', strtotime($test_date_raw)) : date('j F Y');

// Fetch results
$ranking_list = [];
try {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $stmt_res = $pdo->prepare("
        SELECT ar.student_email, ar.score, ar.attendance_status,
               COALESCE(u.name, ar.src_name) AS name,
               COALESCE(u.college_school, '-') AS college_school,
               u.user_id, u.pepp_course AS course_name,
               u.user_photo
        FROM assessment_results ar
        LEFT JOIN users u ON (ar.user_id = u.user_id OR LOWER(ar.student_email) = LOWER(u.email))
        WHERE ar.batch_id IN ($placeholders)
    ");
    $stmt_res->execute($batch_ids);
    $results = $stmt_res->fetchAll(PDO::FETCH_ASSOC);

    // Deduplicate and retain highest scores
    $merged = [];
    foreach ($results as $r) {
        if ($r['attendance_status'] !== 'attended' || $r['score'] === null) {
            continue;
        }
        $uid = !empty($r['user_id']) ? $r['user_id'] : $r['student_email'];
        if (empty($uid)) continue;

        if (!isset($merged[$uid]) || $r['score'] > $merged[$uid]['score']) {
            $merged[$uid] = $r;
        }
    }

    $rankable = array_values($merged);
    usort($rankable, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });

    $prev_score = null;
    $rank = 0;
    $count = 0;
    foreach ($rankable as $r) {
        $count++;
        if ($r['score'] !== $prev_score) {
            $rank = $count;
        }
        $r['computed_rank'] = $rank;
        $ranking_list[] = $r;
        $prev_score = $r['score'];
    }

    // Resolve eligible courses for the study plan
    $eligible_courses = [];
    try {
        $stmt_assign = $pdo->prepare("SELECT assignment_type, assigned_value FROM study_plan_assignments WHERE study_plan_id = ?");
        $stmt_assign->execute([$plan_id]);
        $assignments = $stmt_assign->fetchAll(PDO::FETCH_ASSOC);

        $is_all = false;
        $assigned_names = [];
        foreach ($assignments as $asg) {
            if ($asg['assignment_type'] === 'all') {
                $is_all = true;
                break;
            } elseif ($asg['assignment_type'] === 'course') {
                $assigned_names[] = $asg['assigned_value'];
            }
        }

        if ($is_all) {
            $stmt_courses = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active'");
            $stmt_courses->execute([$year]);
            $eligible_courses = $stmt_courses->fetchAll(PDO::FETCH_COLUMN);
        } else {
            if (!empty($assigned_names)) {
                $placeholders_c = implode(',', array_fill(0, count($assigned_names), '?'));
                $stmt_courses = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE academic_year = ? AND status = 'active' AND course_name IN ($placeholders_c)");
                $stmt_courses->execute(array_merge([$year], $assigned_names));
                $eligible_courses = $stmt_courses->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    } catch (Exception $e) {}

    // Fallback: if no assigned courses resolved, get the course of the published batches
    if (empty($eligible_courses) && !empty($batches)) {
        try {
            $stmt_pc = $pdo->prepare("SELECT course_name FROM pepp_courses WHERE id = ?");
            $stmt_pc->execute([$batches[0]['course_id']]);
            $cname = $stmt_pc->fetchColumn();
            if ($cname) {
                $eligible_courses[] = $cname;
            }
        } catch (Exception $e) {}
    }

    // Load all eligible students
    $all_eligible_students = [];
    if (!empty($eligible_courses)) {
        try {
            $placeholders_c = implode(',', array_fill(0, count($eligible_courses), '?'));
            $stmt_stud = $pdo->prepare("
                SELECT user_id, name, email, college_school, pepp_course AS course_name, user_photo
                FROM users
                WHERE status = 'approved'
                  AND student_status IN ('active', 'completed')
                  AND pepp_academic_year = ?
                  AND LOWER(TRIM(pepp_course)) IN ($placeholders_c)
            ");
            $stmt_stud->execute(array_merge([$year], array_map(function($c) { return strtolower(trim($c)); }, $eligible_courses)));
            $all_eligible_students = $stmt_stud->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // Build attended match sets
    $attended_ids = [];
    $attended_emails = [];
    foreach ($ranking_list as $student) {
        if (!empty($student['user_id'])) {
            $attended_ids[$student['user_id']] = true;
        }
        if (!empty($student['student_email'])) {
            $attended_emails[strtolower(trim($student['student_email']))] = true;
        }
    }

    // Filter to find not-attended students
    $not_attended_list = [];
    $seen_not_attended = [];
    foreach ($all_eligible_students as $student) {
        $has_attended = false;
        if (!empty($student['user_id']) && isset($attended_ids[$student['user_id']])) {
            $has_attended = true;
        }
        if (!empty($student['email']) && isset($attended_emails[strtolower(trim($student['email']))])) {
            $has_attended = true;
        }

        if (!$has_attended) {
            $key = !empty($student['user_id']) ? $student['user_id'] : $student['email'];
            if (empty($key) || isset($seen_not_attended[$key])) {
                continue;
            }
            $seen_not_attended[$key] = true;

            $student['student_email'] = $student['email']; // Map for fallback consistency
            $student['score'] = null;
            $student['computed_rank'] = 'Not Attended';
            $not_attended_list[] = $student;
        }
    }

    // Sort not-attended list alphabetically by student name (case-insensitive, trimmed)
    usort($not_attended_list, function($a, $b) {
        return strcasecmp(trim($a['name'] ?? ''), trim($b['name'] ?? ''));
    });

    // Merge: attended first, then not-attended
    $ranking_list = array_merge($ranking_list, $not_attended_list);
} catch (Exception $e) {
    http_response_code(500);
    exit('Failed to retrieve and merge assessment results: ' . $e->getMessage());
}

if (empty($ranking_list)) {
    http_response_code(404);
    exit('No student attendance or score records found.');
}

if (empty($course_name)) {
    foreach ($ranking_list as $student) {
        if (!empty($student['course_name'])) {
            $course_name = trim(preg_replace('/\s*\([^)]+\)/', '', $student['course_name']));
            break;
        }
    }
}
if (empty($course_name)) {
    $course_name = 'MA/MSc Psychology';
}

// ── Image Helper ──────────────────────────────────────────────────────
function get_jpeg_photo_path($raw_photo_path) {
    if (empty($raw_photo_path)) return null;
    $abs_path = __DIR__ . '/../' . $raw_photo_path;
    if (!file_exists($abs_path) || !is_file($abs_path)) {
        return null;
    }
    $bytes = @file_get_contents($abs_path);
    if ($bytes && substr($bytes, 0, 2) === "\xFF\xD8") {
        return $abs_path;
    }

    // Convert PNG to temporary JPEG using GD dynamically
    try {
        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($bytes);
            if ($im) {
                $scratch_dir = __DIR__ . '/scratch';
                if (!is_dir($scratch_dir)) {
                    @mkdir($scratch_dir, 0755, true);
                }
                $temp_jpg = $scratch_dir . '/tmp_avatar_' . md5($raw_photo_path) . '.jpg';
                if (imagejpeg($im, $temp_jpg, 85)) {
                    imagedestroy($im);
                    return $temp_jpg;
                }
                imagedestroy($im);
            }
        }
    } catch (Exception $e) {}

    return null;
}

// ── Draw Header & Table Labels Function ────────────────────────────────
function draw_page_headers($pdf, $academic_year, $course_name, $chapter_name, $formatted_date) {
    $lm = 54;
    $rm = 595.28 - 54;
    $y = 54;

    // Draw Gold Trophy cup logo vector paths
    $r = 234/255; $g = 179/255; $b = 8/255; // #eab308
    $pdf->fillRect($lm + 8, $y + 5, 20, 14, $r, $g, $b);
    $pdf->fillRect($lm + 16, $y + 19, 4, 6, $r, $g, $b);
    $pdf->fillRect($lm + 11, $y + 25, 14, 3, $r, $g, $b);

    $pdf->line($lm + 8, $y + 8, $lm + 4, $y + 11, 1.5, $r, $g, $b);
    $pdf->line($lm + 4, $y + 11, $lm + 8, $y + 14, 1.5, $r, $g, $b);
    $pdf->line($lm + 28, $y + 8, $lm + 32, $y + 11, 1.5, $r, $g, $b);
    $pdf->line($lm + 32, $y + 11, $lm + 28, $y + 14, 1.5, $r, $g, $b);

    // Mega Test Result branding
    $pdf->setTextColor(234/255, 179/255, 8/255);
    $pdf->text($lm + 42, $y + 2, 18, 'Mega Test', 700);
    $pdf->text($lm + 42, $y + 20, 18, 'Result', 700);
    $pdf->setTextColor(0, 0, 0);

    // Right side academic details shifted to accommodate the logo on the far right
    $right_x = $rm - 70;
    $w = 200;
    $pdf->text($right_x - $w, $y - 3, 20, $academic_year, 700, 'R', $w);
    $pdf->text($right_x - $w, $y + 17, 10, 'Academic Batch', 600, 'R', $w);
    $pdf->text($right_x - $w, $y + 28, 11, $course_name, 500, 'R', $w);

    // PEPP Logo
    $logo_path = get_jpeg_photo_path('admissions/logo_pepp.jpg');
    if (!$logo_path) {
        $direct_path = __DIR__ . '/logo_pepp.jpg';
        if (file_exists($direct_path)) {
            $logo_path = $direct_path;
        }
    }
    if ($logo_path) {
        $pdf->image($logo_path, $rm - 60, $y - 4, 60, 24);
    }

    $y += 42;

    // Dotted separator
    $pdf->setDash([2, 2]);
    $pdf->line($lm, $y, $rm, $y, 0.7, 148/255, 163/255, 184/255);
    $pdf->setDash([]);

    $y += 15;

    // Chapter Title
    $pdf->text($lm, $y, 11, 'Test: ', 600);
    $pdf->setTextColor(234/255, 179/255, 8/255);
    $pdf->text($lm + $pdf->width('Test: ', 11, 600), $y, 11, $chapter_name, 700);
    $pdf->setTextColor(0, 0, 0);

    $y += 15;

    // Test Date
    $pdf->text($lm, $y, 11, 'Test Date: ', 600);
    $pdf->setTextColor(234/255, 179/255, 8/255);
    $pdf->text($lm + $pdf->width('Test Date: ', 11, 600), $y, 11, $formatted_date, 700);
    $pdf->setTextColor(0, 0, 0);

    $y += 18;

    // Table header border
    $pdf->setDash([2, 2]);
    $pdf->line($lm, $y, $rm, $y, 0.7, 148/255, 163/255, 184/255);
    $pdf->setDash([]);

    $y += 5;

    $x_sl = $lm;
    $x_name = $lm + 55;
    $x_score = $lm + 330;
    $x_rank = $lm + 410;

    $pdf->text($x_sl, $y, 9.5, 'Sl. No.', 600);
    $pdf->text($x_name, $y, 9.5, 'Student Name', 600);
    $pdf->text($x_score, $y, 9.5, 'Score', 600, 'C', 50);
    $pdf->text($x_rank, $y, 9.5, 'Rank', 600, 'C', 50);

    $y += 13;

    // Bottom border of headers
    $pdf->setDash([2, 2]);
    $pdf->line($lm, $y, $rm, $y, 0.7, 148/255, 163/255, 184/255);
    $pdf->setDash([]);

    return $y + 12;
}

// ── Draw Rank Badges ──────────────────────────────────────────────────
function draw_rank_badge($pdf, $cx, $cy, $rank) {
    if ($rank === 1) {
        $pdf->circle($cx, $cy, 9, 0.7, true, 234/255, 179/255, 8/255); // Gold
        $pdf->setTextColor(1, 1, 1);
        $pdf->text($cx - 3, $cy - 4.5, 9, '1', 700);
        $pdf->setTextColor(0, 0, 0);
    } elseif ($rank === 2) {
        $pdf->circle($cx, $cy, 9, 0.7, true, 148/255, 163/255, 184/255); // Silver
        $pdf->setTextColor(1, 1, 1);
        $pdf->text($cx - 3, $cy - 4.5, 9, '2', 700);
        $pdf->setTextColor(0, 0, 0);
    } elseif ($rank === 3) {
        $pdf->circle($cx, $cy, 9, 0.7, true, 180/255, 83/255, 9/255); // Bronze/Brown
        $pdf->setTextColor(1, 1, 1);
        $pdf->text($cx - 3, $cy - 4.5, 9, '3', 700);
        $pdf->setTextColor(0, 0, 0);
    } else {
        $r_str = (string)$rank;
        $offset = strlen($r_str) > 1 ? 5 : 3;
        $pdf->text($cx - $offset, $cy - 4.5, 9.5, $r_str, 500);
    }
}

// ── Generate PDF ──────────────────────────────────────────────────────
$pdf = new MultiPagePDF();
$lm = 54;
$rm = 595.28 - 54;

$y_row = draw_page_headers($pdf, $year, $course_name, $chapter_name, $formatted_date);
$sl_no = 1;

foreach ($ranking_list as $r) {
    // Check page overflow
    if ($y_row + 28 > 780) {
        $pdf->addPage();
        $y_row = draw_page_headers($pdf, $year, $course_name, $chapter_name, $formatted_date);
    }

    // Column coordinate variables
    $x_sl = $lm;
    $x_photo = $lm + 25;
    $x_name = $lm + 55;
    $x_score = $lm + 330;
    $x_rank = $lm + 410;

    // Sl. No
    $pdf->text($x_sl, $y_row + 8, 10, $sl_no . '.', 400);

    // Student Photo (Circular clipped avatar)
    $photo_path = get_jpeg_photo_path($r['user_photo'] ?? null);
    $pdf->clipCircleImage($photo_path, $x_photo + 11, $y_row + 12, 11);

    // Student Name
    $pdf->text($x_name, $y_row + 8, 10, $r['name'], 700);

    // Score formatting & privacy
    if ($r['score'] === null) {
        $score_text = '—';
    } else {
        $score_val = (float)$r['score'];
        if ($score_val <= 10.0) {
            $score_text = '***';
        } else {
            if (floor($score_val) == $score_val) {
                $score_text = number_format($score_val, 0);
            } else {
                $score_text = number_format($score_val, 2);
            }
        }
    }
    $pdf->text($x_score, $y_row + 8, 10, $score_text, 400, 'C', 50);

    // Rank / Status
    if ($r['computed_rank'] === 'Not Attended') {
        $pdf->text($x_rank - 15, $y_row + 8, 8, 'Not Attended', 500, 'C', 80);
    } else {
        draw_rank_badge($pdf, $x_rank + 25, $y_row + 12, (int)$r['computed_rank']);
    }

    // Dotted separator line between student rows
    $pdf->setDash([1, 3]);
    $pdf->line($lm, $y_row + 28, $rm, $y_row + 28, 0.5, 203/255, 213/255, 225/255);
    $pdf->setDash([]);

    $y_row += 28;
    $sl_no++;
}

$pdf_bytes = $pdf->output($pdo, $year);

// Sanitize filename
$clean_chapter = preg_replace('/[^A-Za-z0-9_-]/', '_', $chapter_name);
$clean_chapter = preg_replace('/_+/', '_', $clean_chapter);
$clean_chapter = trim($clean_chapter, '_');
$clean_date = !empty($test_date_raw) ? date('d-M-Y', strtotime($test_date_raw)) : date('d-M-Y');

$filename = "PEPP_Mega_Test_Rank_List_{$clean_chapter}_{$clean_date}.pdf";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf_bytes));
echo $pdf_bytes;
exit();
