<?php
/**
 * JOSE key/signature plumbing: turn a JSON Web Key into a PEM that OpenSSL
 * accepts, and reshape a JWS ECDSA signature into the DER form openssl_verify
 * expects.
 *
 * GoogleIdTokenVerifier cannot cover this: it reads Google's legacy endpoint,
 * which hands back ready-made PEM certificates for RSA keys. ThaiD publishes a
 * standard JWKS and signs with ES256, so both the key and the signature need
 * converting before OpenSSL will look at them.
 */
class Jwks {
    /**
     * SubjectPublicKeyInfo header for an uncompressed P-256 point:
     * SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING (66 bytes) }.
     * The 65 bytes that follow are 0x04 || X || Y.
     */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** AlgorithmIdentifier for rsaEncryption with a NULL parameter. */
    private const RSA_ALGORITHM_ID = '300d06092a864886f70d0101010500';

    /** @param array<string,mixed> $jwk */
    public static function toPem(array $jwk): ?string {
        switch ((string) ($jwk['kty'] ?? '')) {
            case 'EC':
                return self::ecToPem($jwk);
            case 'RSA':
                return self::rsaToPem($jwk);
            default:
                return null;
        }
    }

    /**
     * JWS carries an ECDSA signature as the raw concatenation R || S, while
     * OpenSSL wants SEQUENCE { INTEGER r, INTEGER s }.
     */
    public static function ecdsaSignatureToDer(string $raw): ?string {
        $length = strlen($raw);
        if ($length === 0 || $length % 2 !== 0) {
            return null;
        }
        $half = intdiv($length, 2);
        $pair = self::derInteger(substr($raw, 0, $half)) . self::derInteger(substr($raw, $half));
        return self::derSequence($pair);
    }

    public static function b64urlDecode(string $data): ?string {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /** @param array<string,mixed> $jwk */
    private static function ecToPem(array $jwk): ?string {
        if (($jwk['crv'] ?? '') !== 'P-256') {
            return null;
        }
        $x = self::b64urlDecode((string) ($jwk['x'] ?? ''));
        $y = self::b64urlDecode((string) ($jwk['y'] ?? ''));
        // Coordinates are fixed-width on P-256; anything else means a malformed key.
        if ($x === null || $y === null || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }
        return self::pem((string) hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $x . $y);
    }

    /** @param array<string,mixed> $jwk */
    private static function rsaToPem(array $jwk): ?string {
        $n = self::b64urlDecode((string) ($jwk['n'] ?? ''));
        $e = self::b64urlDecode((string) ($jwk['e'] ?? ''));
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $rsaKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
        $bitString = "\x03" . self::derLength(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
        return self::pem(self::derSequence((string) hex2bin(self::RSA_ALGORITHM_ID) . $bitString));
    }

    private static function pem(string $der): string {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derSequence(string $content): string {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    /** DER integers are signed, so a leading high bit needs a zero byte in front. */
    private static function derInteger(string $bytes): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
