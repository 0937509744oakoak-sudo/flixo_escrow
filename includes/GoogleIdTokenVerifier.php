<?php
// Verify Google Sign-In ID tokens (GIS) using Google's public certs — Client ID only.

class GoogleIdTokenVerifier {
    private string $clientId;
    private static ?array $certCache = null;
    private static int $certCachedAt = 0;
    private const CERT_TTL = 3600;
    private const CERT_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    public function __construct(string $clientId) {
        $this->clientId = trim($clientId);
    }

    /**
     * @return array{sub:string,email:string,name?:string,picture?:string,email_verified?:bool|string}
     */
    public function verify(string $idToken): array {
        if ($this->clientId === '') {
            throw new RuntimeException('Google Sign-In ยังไม่ได้ตั้งค่า');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('โทเคนไม่ถูกต้อง');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $header = json_decode($this->b64urlDecode($headerB64), true);
        $payload = json_decode($this->b64urlDecode($payloadB64), true);
        if (!is_array($header) || !is_array($payload)) {
            throw new InvalidArgumentException('โทเคนไม่ถูกต้อง');
        }
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new InvalidArgumentException('อัลกอริทึมไม่รองรับ');
        }

        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            throw new InvalidArgumentException('โทเคนไม่ถูกต้อง');
        }

        $certs = $this->getCerts(false);
        if (!isset($certs[$kid])) {
            $certs = $this->getCerts(true);
        }
        if (!isset($certs[$kid])) {
            throw new InvalidArgumentException('ไม่พบกุญแจยืนยันโทเคน');
        }

        $signature = $this->b64urlDecode($sigB64);
        $ok = openssl_verify(
            $headerB64 . '.' . $payloadB64,
            $signature,
            $certs[$kid],
            OPENSSL_ALGO_SHA256
        );
        if ($ok !== 1) {
            throw new InvalidArgumentException('ลายเซ็นโทเคนไม่ถูกต้อง');
        }

        $now = time();
        if ((int) ($payload['exp'] ?? 0) < $now) {
            throw new InvalidArgumentException('โทเคนหมดอายุ กรุณาลองใหม่');
        }
        if ((int) ($payload['iat'] ?? $now) > $now + 300) {
            throw new InvalidArgumentException('โทเคนยังไม่พร้อมใช้งาน');
        }

        $aud = $payload['aud'] ?? '';
        $audOk = is_string($aud)
            ? hash_equals($this->clientId, $aud)
            : (is_array($aud) && in_array($this->clientId, $aud, true));
        if (!$audOk) {
            throw new InvalidArgumentException('โทเคนไม่ได้มีไว้สำหรับแอปนี้');
        }

        $iss = (string) ($payload['iss'] ?? '');
        if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new InvalidArgumentException('ผู้ออกโทเคนไม่ถูกต้อง');
        }

        $sub = trim((string) ($payload['sub'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        if ($sub === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('บัญชี Google ไม่มีอีเมลที่ใช้งานได้');
        }

        $verified = $payload['email_verified'] ?? false;
        if (!($verified === true || $verified === 'true' || $verified === 1 || $verified === '1')) {
            throw new InvalidArgumentException('อีเมล Google ยังไม่ได้ยืนยัน');
        }

        return $payload;
    }

    /** @return array<string, string> kid => PEM cert */
    private function getCerts(bool $forceRefresh): array {
        if (
            !$forceRefresh
            && self::$certCache !== null
            && (time() - self::$certCachedAt) < self::CERT_TTL
        ) {
            return self::$certCache;
        }

        $json = $this->httpGet(self::CERT_URL);
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            throw new RuntimeException('โหลดกุญแจ Google ไม่สำเร็จ');
        }

        self::$certCache = $decoded;
        self::$certCachedAt = time();
        return self::$certCache;
    }

    private function httpGet(string $url): string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code !== 200) {
                throw new RuntimeException('เชื่อมต่อ Google ไม่ได้');
            }
            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "Accept: application/json\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new RuntimeException('เชื่อมต่อ Google ไม่ได้');
        }
        return $body;
    }

    private function b64urlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('โทเคนไม่ถูกต้อง');
        }
        return $decoded;
    }
}
