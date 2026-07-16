<?php
/**
 * Minimal pure-PHP Web Push (VAPID) sender — no Composer dependency.
 *
 * Sends "silent" pushes (zero-length body) so no payload encryption
 * (RFC 8291: ECDH + HKDF + AES-128-GCM) is required. VAPID only needs an
 * ES256-signed JWT, which is built here using openssl_sign() against the
 * app's EC (P-256) key pair. The browser's service worker `push` handler is
 * expected to fetch the actual notification content over a normal
 * same-origin request (see sw.js).
 *
 * Result codes from send():
 *   'sent'    — push service accepted the message (HTTP 2xx/201)
 *   'gone'    — subscription is no longer valid (HTTP 404/410) — caller should delete it
 *   'error'   — request failed for another reason (network, 4xx/5xx)
 *   'disabled'— VAPID keys are not configured
 */
class WebPush
{
    public static function isConfigured(): bool
    {
        return defined('PUSH_NOTIFICATIONS_ENABLED') && PUSH_NOTIFICATIONS_ENABLED;
    }

    /**
     * @param array{endpoint:string,p256dh?:string,auth?:string} $subscription
     * @return string one of: sent|gone|error|disabled
     */
    public static function send(array $subscription, int $ttl = 2419200): string
    {
        if (!self::isConfigured()) {
            return 'disabled';
        }

        $endpoint = trim($subscription['endpoint'] ?? '');
        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return 'error';
        }

        $origin = self::originOf($endpoint);
        if ($origin === '') {
            return 'error';
        }

        try {
            $jwt = self::buildVapidJwt($origin);
        } catch (Throwable $e) {
            error_log('WebPush: VAPID JWT build failed — ' . $e->getMessage());
            return 'error';
        }

        $authHeader = 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authHeader,
                'TTL: ' . max(0, $ttl),
                'Content-Length: 0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($status === 201 || $status === 200 || $status === 202) {
            return 'sent';
        }
        if ($status === 404 || $status === 410) {
            return 'gone';
        }

        if ($curlErr !== '') {
            error_log('WebPush: send failed (curl) — ' . $curlErr);
        } else {
            error_log('WebPush: send failed — HTTP ' . $status . ' from ' . $origin);
        }
        return 'error';
    }

    private static function originOf(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    private static function buildVapidJwt(string $audience): string
    {
        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:support@localhost',
        ]));

        $unsigned = $header . '.' . $payload;

        $privateKeyPem = self::buildEcPrivateKeyPem(VAPID_PRIVATE_KEY, VAPID_PUBLIC_KEY);
        $pkey = openssl_pkey_get_private($privateKeyPem);
        if ($pkey === false) {
            throw new RuntimeException('Unable to load VAPID private key: ' . openssl_error_string());
        }

        $derSignature = '';
        $ok = openssl_sign($unsigned, $derSignature, $pkey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }

        $rawSignature = self::derSignatureToRaw($derSignature, 32);

        return $unsigned . '.' . self::base64UrlEncode($rawSignature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');
        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    private static function fixedLengthBytes(string $bin, int $len): string
    {
        $bin = ltrim($bin, "\x00");
        if (strlen($bin) > $len) {
            $bin = substr($bin, -$len);
        }
        return str_pad($bin, $len, "\x00", STR_PAD_LEFT);
    }

    /** DER-encode an unsigned big-endian integer for ASN.1 (prefixes 0x00 if the high bit is set). */
    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * Build a SEC1 "EC PRIVATE KEY" PEM for curve prime256v1 from the raw
     * 32-byte private scalar and the raw 65-byte uncompressed public point
     * (0x04 || X || Y) — both stored base64url-encoded in VAPID_* config.
     */
    private static function buildEcPrivateKeyPem(string $privateKeyB64Url, string $publicKeyB64Url): string
    {
        $d = self::fixedLengthBytes(self::base64UrlDecode($privateKeyB64Url), 32);
        $pub = self::base64UrlDecode($publicKeyB64Url);
        if (strlen($pub) !== 65 || $pub[0] !== "\x04") {
            throw new RuntimeException('VAPID_PUBLIC_KEY must be an uncompressed P-256 point');
        }

        $prime256v1Oid = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"; // OID 1.2.840.10045.3.1.7
        $parameters = "\xA0" . self::derLength(strlen($prime256v1Oid)) . $prime256v1Oid;

        $publicKeyBitString = "\x03" . self::derLength(strlen($pub) + 1) . "\x00" . $pub;
        $publicKeyField = "\xA1" . self::derLength(strlen($publicKeyBitString)) . $publicKeyBitString;

        $privateKeyOctet = "\x04" . self::derLength(strlen($d)) . $d;

        $version = "\x02\x01\x01"; // INTEGER 1

        $body = $version . $privateKeyOctet . $parameters . $publicKeyField;
        $der = "\x30" . self::derLength(strlen($body)) . $body;

        $pem = "-----BEGIN EC PRIVATE KEY-----\n";
        $pem .= chunk_split(base64_encode($der), 64, "\n");
        $pem .= "-----END EC PRIVATE KEY-----\n";

        return $pem;
    }

    /** Convert a DER ECDSA signature (SEQUENCE of two INTEGERs) to raw fixed-length r||s (JOSE format). */
    private static function derSignatureToRaw(string $der, int $componentLen): string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            throw new RuntimeException('Invalid DER signature: expected SEQUENCE');
        }
        [$seqLen, $offset] = self::readDerLength($der, $offset);

        [$r, $offset] = self::readDerInteger($der, $offset);
        [$s, $offset] = self::readDerInteger($der, $offset);

        return self::fixedLengthBytes($r, $componentLen) . self::fixedLengthBytes($s, $componentLen);
    }

    private static function readDerLength(string $der, int $offset): array
    {
        $first = ord($der[$offset]);
        $offset++;
        if ($first < 128) {
            return [$first, $offset];
        }
        $numBytes = $first & 0x7F;
        $len = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            $len = ($len << 8) | ord($der[$offset]);
            $offset++;
        }
        return [$len, $offset];
    }

    private static function readDerInteger(string $der, int $offset): array
    {
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new RuntimeException('Invalid DER signature: expected INTEGER');
        }
        [$len, $offset] = self::readDerLength($der, $offset);
        $bytes = substr($der, $offset, $len);
        $offset += $len;
        return [$bytes, $offset];
    }
}
