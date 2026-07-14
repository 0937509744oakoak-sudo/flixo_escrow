<?php
// includes/PiiEncryption.php — AES-256-GCM encryption for PII at rest

class PiiEncryption {
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private static function isProduction(): bool {
        return (defined('APP_ENV') && APP_ENV === 'production')
            || (defined('FORCE_HTTPS') && FORCE_HTTPS);
    }

    private static function getKey(): string {
        $raw = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? ENCRYPTION_KEY : (getenv('ENCRYPTION_KEY') ?: '');
        if (strlen($raw) >= 32) {
            return hash('sha256', $raw, true);
        }
        if (self::isProduction()) {
            throw new RuntimeException('ENCRYPTION_KEY must be set in production (min 32 chars).');
        }
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "[WARN] Using dev encryption key — set ENCRYPTION_KEY before production.\n");
        }
        return hash('sha256', 'flixo_dev_encryption_key_' . (defined('DB_NAME') ? DB_NAME : 'flixo'), true);
    }

    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return 'enc:' . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $stored): string {
        if ($stored === '' || !str_starts_with($stored, 'enc:')) {
            return $stored;
        }
        $data = base64_decode(substr($stored, 4), true);
        if ($data === false || strlen($data) < self::IV_LEN + self::TAG_LEN) {
            return $stored;
        }
        $iv = substr($data, 0, self::IV_LEN);
        $tag = substr($data, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($data, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain !== false ? $plain : $stored;
    }

    public static function isEncrypted(string $value): bool {
        return str_starts_with($value, 'enc:');
    }

    /**
     * Encrypt uploaded KYC file in place; returns new filename (with .enc suffix).
     */
    public static function encryptFile(string $filepath): string {
        if (!is_file($filepath)) {
            throw new RuntimeException('File not found for encryption.');
        }
        $plaintext = file_get_contents($filepath);
        if ($plaintext === false) {
            throw new RuntimeException('Cannot read file for encryption.');
        }
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('File encryption failed.');
        }
        $encPath = $filepath . '.enc';
        if (file_put_contents($encPath, $iv . $tag . $ciphertext) === false) {
            throw new RuntimeException('Cannot write encrypted file.');
        }
        @unlink($filepath);
        return basename($encPath);
    }

    /**
     * Decrypt KYC file to a temp path for serving; caller must unlink after use.
     */
    public static function decryptFileToTemp(string $encPath): ?string {
        if (!is_file($encPath)) {
            return null;
        }
        if (!str_ends_with($encPath, '.enc')) {
            return $encPath;
        }
        $data = file_get_contents($encPath);
        if ($data === false || strlen($data) < self::IV_LEN + self::TAG_LEN) {
            return null;
        }
        $iv = substr($data, 0, self::IV_LEN);
        $tag = substr($data, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($data, self::IV_LEN + self::TAG_LEN);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::getKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'kyc_');
        if ($tmp === false || file_put_contents($tmp, $plain) === false) {
            return null;
        }
        return $tmp;
    }

    public static function guessMimeFromPath(string $path): string {
        $base = preg_replace('/\.enc$/', '', basename($path));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
