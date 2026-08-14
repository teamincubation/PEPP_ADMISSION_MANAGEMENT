<?php
/**
 * PEPP Learning — AES-256-GCM authenticated encryption.
 *
 * Ciphertext format: base64( nonce[12] ‖ tag[16] ‖ ciphertext[N] )
 * Key: 256-bit, derived via SHA-256 from the configured secret.
 * Source: environment variable PEPP_SENSITIVE_DATA_KEY,
 *         fallback to config/secrets.php (gitignored).
 *
 * NEVER commit the encryption key to version control.
 */

/**
 * Resolve the 32-byte encryption key from environment or gitignored config.
 * @throws RuntimeException if no key is configured
 */
function pepp_get_encryption_key(): string {
    // Priority 1: Environment variable (server config / .htaccess / php.ini)
    $key = getenv('PEPP_SENSITIVE_DATA_KEY');
    if ($key !== false && $key !== '') {
        return hash('sha256', $key, true); // 32 bytes for AES-256
    }

    // Priority 2: Gitignored secrets file
    $secrets_file = __DIR__ . '/../config/secrets.php';
    if (file_exists($secrets_file)) {
        if (!defined('PEPP_SENSITIVE_DATA_KEY')) {
            require_once $secrets_file;
        }
        if (defined('PEPP_SENSITIVE_DATA_KEY') && PEPP_SENSITIVE_DATA_KEY !== '') {
            return hash('sha256', PEPP_SENSITIVE_DATA_KEY, true);
        }
    }

    throw new RuntimeException(
        'PEPP_SENSITIVE_DATA_KEY is not configured. '
      . 'Set it as an environment variable or in config/secrets.php (gitignored).'
    );
}

/**
 * Encrypt plaintext using AES-256-GCM (authenticated encryption).
 *
 * @param  string $plaintext  The data to encrypt
 * @return string             base64( nonce[12] ‖ tag[16] ‖ ciphertext[N] )
 * @throws RuntimeException   On encryption failure or missing key
 */
function pepp_encrypt(string $plaintext): string {
    $key    = pepp_get_encryption_key();
    $cipher = 'aes-256-gcm';
    $nonce  = random_bytes(12);  // 96-bit nonce (GCM recommended)
    $tag    = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        $cipher,
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',   // no additional authenticated data
        16    // 128-bit tag length
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }

    // Pack: nonce(12) + tag(16) + ciphertext(N)
    return base64_encode($nonce . $tag . $ciphertext);
}

/**
 * Decrypt AES-256-GCM ciphertext with authentication verification.
 *
 * NEVER returns corrupted or unauthenticated plaintext.
 * If the auth tag does not match, a RuntimeException is thrown.
 *
 * @param  string $encoded    base64-encoded value from pepp_encrypt()
 * @return string             The original plaintext
 * @throws RuntimeException   On tag mismatch, corrupted data, or missing key
 */
function pepp_decrypt(string $encoded): string {
    $key    = pepp_get_encryption_key();
    $cipher = 'aes-256-gcm';
    $data   = base64_decode($encoded, true);

    if ($data === false || strlen($data) < 28) {
        // Minimum valid length: 12 (nonce) + 16 (tag) + 0 (empty plaintext) = 28
        throw new RuntimeException('Decryption failed: invalid encoded data');
    }

    $nonce      = substr($data, 0, 12);
    $tag        = substr($data, 12, 16);
    $ciphertext = substr($data, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        $cipher,
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($plaintext === false) {
        // GCM authentication tag verification failed.
        // Data has been tampered with, or the key is wrong.
        throw new RuntimeException(
            'Decryption failed: authentication tag verification failed'
        );
    }

    return $plaintext;
}

/**
 * Mask Aadhaar number for display.
 * Input: 12-digit Aadhaar (plaintext, before encryption).
 * Output: 'XXXX XXXX 1234'
 */
function mask_aadhaar(string $aadhaar): string {
    $digits = preg_replace('/\D/', '', $aadhaar);
    if (strlen($digits) !== 12) {
        return 'XXXX XXXX XXXX';
    }
    return 'XXXX XXXX ' . substr($digits, -4);
}

/**
 * Mask bank account number for display.
 * Input: full account number (plaintext, before encryption).
 * Output: 'XXXX1234' (last 4 digits visible)
 */
function mask_bank_account(string $account): string {
    $clean = preg_replace('/\s/', '', $account);
    $len   = strlen($clean);
    if ($len <= 4) {
        return str_repeat('X', $len);
    }
    return str_repeat('X', $len - 4) . substr($clean, -4);
}
