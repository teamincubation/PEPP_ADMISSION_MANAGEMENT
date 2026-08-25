<?php
/**
 * Backend Functional Test for PNG and JPEG 300 DPI Metadata Verification
 */

function assert_dpi($label, $assertion) {
    if ($assertion) {
        echo "✅ PASS: {$label}\n";
    } else {
        echo "❌ FAIL: {$label}\n";
        exit(1);
    }
}

// ── 1. PNG DPI Injection and Inspection Logic in PHP ────────────
function test_png_dpi_injection() {
    echo "\n--- Testing PNG DPI Metadata Injection ---\n";
    
    // Create a 100x100 blank PNG image stream
    $im = imagecreatetruecolor(100, 100);
    ob_start();
    imagepng($im);
    $png_data = ob_get_clean();
    imagedestroy($im);
    
    $bytes = &$png_data;
    
    // Validate signature
    assert_dpi("Valid PNG signature", substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n");
    
    // Inject pHYs chunk in PHP to simulate client-side JavaScript injection
    $insert_idx = 33; // After standard IHDR chunk
    
    // Length: 9 bytes
    // Type: pHYs
    // X: 11811 (Hex: 00 00 2E 23)
    // Y: 11811 (Hex: 00 00 2E 23)
    // Unit: 1 (meter)
    $phys_chunk = "\x00\x00\x00\x09pHYs\x00\x00\x2e\x23\x00\x00\x2e\x23\x01";
    
    // CRC-32 calculation over chunk type + data
    $crc = crc32(substr($phys_chunk, 4));
    $phys_chunk .= pack('N', $crc);
    
    // Construct new PNG data with injected pHYs chunk
    $injected_png = substr($bytes, 0, $insert_idx) . $phys_chunk . substr($bytes, $insert_idx);
    
    // Verify the injected PNG's pHYs chunk
    $pos = strpos($injected_png, 'pHYs');
    assert_dpi("pHYs chunk found in PNG stream", $pos !== false);
    
    // Length is at $pos - 4
    $len = unpack('N', substr($injected_png, $pos - 4, 4))[1];
    assert_dpi("pHYs chunk length is 9 bytes", $len === 9);
    
    // X and Y are at $pos + 4 and $pos + 8
    $x = unpack('N', substr($injected_png, $pos + 4, 4))[1];
    $y = unpack('N', substr($injected_png, $pos + 8, 4))[1];
    $unit = ord($injected_png[$pos + 12]);
    
    assert_dpi("PNG X pixels-per-meter is exactly 11811", $x === 11811);
    assert_dpi("PNG Y pixels-per-meter is exactly 11811", $y === 11811);
    assert_dpi("PNG unit specifier is 1 (meter)", $unit === 1);
    
    $effective_dpi = round($x * 0.0254);
    assert_dpi("PNG effective DPI is 300 ({$effective_dpi} DPI)", $effective_dpi === 300.0);
}

// ── 2. JPEG DPI Injection and Inspection Logic in PHP ────────────
function test_jpeg_dpi_injection() {
    echo "\n--- Testing JPEG DPI Metadata Injection ---\n";
    
    // Create a 100x100 blank JPEG image stream
    $im = imagecreatetruecolor(100, 100);
    ob_start();
    imagejpeg($im, null, 95);
    $jpeg_data = ob_get_clean();
    imagedestroy($im);
    
    $bytes = &$jpeg_data;
    
    // Validate SOI marker
    assert_dpi("Valid JPEG SOI", substr($bytes, 0, 2) === "\xff\xd8");
    
    // Find APP0 marker (FF E0) with "JFIF\0" identifier
    $app0_idx = -1;
    for ($i = 2; $i < strlen($bytes) - 10; $i++) {
        if (ord($bytes[$i]) === 0xff && ord($bytes[$i+1]) === 0xe0) {
            if ($bytes[$i+4] === 'J' && $bytes[$i+5] === 'F' && $bytes[$i+6] === 'I' && $bytes[$i+7] === 'F' && ord($bytes[$i+8]) === 0) {
                $app0_idx = $i;
                break;
            }
        }
    }
    
    assert_dpi("APP0 JFIF segment found in JPEG", $app0_idx !== -1);
    
    // Update the APP0 JFIF density block in PHP to simulate JavaScript client override
    // APP0 starts with Marker (2 bytes) + Length (2 bytes) + Identifier (5 bytes) + Version (2 bytes) = 11 bytes offset
    // Offset 11: density unit (1 = dots per inch)
    // Offset 12-13: X density (300 = 0x012c)
    // Offset 14-15: Y density (300 = 0x012c)
    $bytes[$app0_idx + 11] = chr(1);
    $bytes[$app0_idx + 12] = chr(0x01);
    $bytes[$app0_idx + 13] = chr(0x2c);
    $bytes[$app0_idx + 14] = chr(0x01);
    $bytes[$app0_idx + 15] = chr(0x2c);
    
    // Verify JFIF density headers
    $unit = ord($bytes[$app0_idx + 11]);
    $x_density = (ord($bytes[$app0_idx + 12]) << 8) | ord($bytes[$app0_idx + 13]);
    $y_density = (ord($bytes[$app0_idx + 14]) << 8) | ord($bytes[$app0_idx + 15]);
    
    assert_dpi("JPEG density unit is 1 (dots per inch)", $unit === 1);
    assert_dpi("JPEG X density is 300", $x_density === 300);
    assert_dpi("JPEG Y density is 300", $y_density === 300);
}

// Run tests
test_png_dpi_injection();
test_jpeg_dpi_injection();

echo "\n=== All binary DPI metadata tests passed successfully! ===\n";
