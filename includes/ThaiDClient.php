<?php
/**
 * OpenID Connect relying-party client for ThaiD (DOPA Digital ID).
 *
 * Authorization-code flow with state + nonce; PKCE is opt-in because DOPA's
 * discovery document does not advertise code_challenge_methods_supported.
 * This is the first redirect-based OAuth flow in the codebase — Google Sign-In
 * uses GIS popup mode and never leaves the page — so nothing here is shared
 * with AuthController.
 */
require_once __DIR__ . '/Jwks.php';

class ThaiDClient {
    private const CACHE_TTL = 3600;
    private const CLOCK_SKEW = 60;
    private const HTTP_TIMEOUT = 20;

    private static ?array $discovery = null;
    private static int $discoveryAt = 0;
    private static ?array $jwks = null;
    private static int $jwksAt = 0;

    public static function isConfigured(): bool {
        return defined('THAID_ENABLED') && THAID_ENABLED;
    }

    /**
     * PKCE verifier per RFC 7636: 43–128 chars from the unreserved set.
     */
    public static function generateCodeVerifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public static function codeChallenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function authorizationUrl(string $state, string $nonce, ?string $codeVerifier = null): string {
        self::assertConfigured();

        $params = [
            'response_type' => 'code',
            'client_id' => THAID_CLIENT_ID,
            'redirect_uri' => THAID_REDIRECT_URI,
            'scope' => THAID_SCOPE,
            'state' => $state,
            'nonce' => $nonce,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $params['code_challenge'] = self::codeChallenge($codeVerifier);
            $params['code_challenge_method'] = 'S256';
        }

        $endpoint = (string) self::discovery()['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params);
    }

    /**
     * @return array{access_token:string,id_token:string,token_type?:string,expires_in?:int}
     */
    public static function exchangeCode(string $code, ?string $codeVerifier = null): array {
        self::assertConfigured();

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => THAID_REDIRECT_URI,
            'client_id' => THAID_CLIENT_ID,
        ];
        if ($codeVerifier !== null && $codeVerifier !== '') {
            $form['code_verifier'] = $codeVerifier;
        }

        $response = self::post((string) self::discovery()['token_endpoint'], $form);
        if (isset($response['error'])) {
            throw new RuntimeException('ThaiD ปฏิเสธคำขอโทเคน: ' . (string) $response['error']);
        }
        if (empty($response['id_token'])) {
            throw new RuntimeException('ThaiD ไม่ได้ส่ง id_token กลับมา');
        }
        return $response;
    }

    /**
     * Verifies signature, issuer, audience, expiry and nonce, then returns the claims.
     *
     * @return array<string,mixed>
     */
    public static function verifyIdToken(string $idToken, string $expectedNonce): array {
        self::assertConfigured();

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('รูปแบบ id_token ไม่ถูกต้อง');
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode((string) Jwks::b64urlDecode($headerB64), true);
        $claims = json_decode((string) Jwks::b64urlDecode($payloadB64), true);
        $signature = Jwks::b64urlDecode($signatureB64);
        if (!is_array($header) || !is_array($claims) || $signature === null) {
            throw new InvalidArgumentException('รูปแบบ id_token ไม่ถูกต้อง');
        }

        $alg = (string) ($header['alg'] ?? '');
        if ($alg !== 'ES256' && $alg !== 'RS256') {
            throw new InvalidArgumentException('อัลกอริทึมลายเซ็นไม่รองรับ: ' . $alg);
        }
        $kid = (string) ($header['kid'] ?? '');
        if ($kid === '') {
            throw new InvalidArgumentException('id_token ไม่ระบุ kid');
        }

        self::verifySignature($alg, $kid, $headerB64 . '.' . $payloadB64, $signature);

        if (!hash_equals(THAID_ISSUER, rtrim((string) ($claims['iss'] ?? ''), '/'))) {
            throw new InvalidArgumentException('ผู้ออก id_token ไม่ถูกต้อง');
        }

        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud)
            ? in_array(THAID_CLIENT_ID, $aud, true)
            : hash_equals(THAID_CLIENT_ID, (string) $aud);
        if (!$audOk) {
            throw new InvalidArgumentException('id_token ไม่ได้ออกให้แอปนี้');
        }

        $now = time();
        if ((int) ($claims['exp'] ?? 0) + self::CLOCK_SKEW < $now) {
            throw new InvalidArgumentException('id_token หมดอายุ กรุณายืนยันตัวตนใหม่');
        }
        if ((int) ($claims['iat'] ?? $now) - self::CLOCK_SKEW > $now) {
            throw new InvalidArgumentException('id_token ยังไม่ถึงเวลาใช้งาน');
        }

        // Without this, an id_token captured from another session could be replayed.
        if (!hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new InvalidArgumentException('nonce ไม่ตรงกับคำขอที่เริ่มไว้');
        }

        return $claims;
    }

    /** @return array<string,mixed> */
    public static function userInfo(string $accessToken): array {
        self::assertConfigured();
        $endpoint = self::discovery()['userinfo_endpoint'] ?? '';
        if ($endpoint === '') {
            return [];
        }
        $response = self::request('GET', (string) $endpoint, null, ['Authorization: Bearer ' . $accessToken]);
        return is_array($response) ? $response : [];
    }

    private static function verifySignature(string $alg, string $kid, string $signingInput, string $signature): void {
        $pem = self::publicKeyPem($kid, false) ?? self::publicKeyPem($kid, true);
        if ($pem === null) {
            throw new InvalidArgumentException('ไม่พบกุญแจสาธารณะของ ThaiD สำหรับ kid นี้');
        }

        if ($alg === 'ES256') {
            $der = Jwks::ecdsaSignatureToDer($signature);
            if ($der === null) {
                throw new InvalidArgumentException('ลายเซ็น id_token ไม่ถูกต้อง');
            }
            $signature = $der;
        }

        if (openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('ลายเซ็น id_token ไม่ถูกต้อง');
        }
    }

    private static function publicKeyPem(string $kid, bool $forceRefresh): ?string {
        foreach (self::jwks($forceRefresh) as $jwk) {
            if (is_array($jwk) && (string) ($jwk['kid'] ?? '') === $kid) {
                return Jwks::toPem($jwk);
            }
        }
        return null;
    }

    /** @return array<int,mixed> */
    private static function jwks(bool $forceRefresh): array {
        if (!$forceRefresh && self::$jwks !== null && (time() - self::$jwksAt) < self::CACHE_TTL) {
            return self::$jwks;
        }
        $uri = (string) (self::discovery()['jwks_uri'] ?? (THAID_ISSUER . '/jwks/'));
        $document = self::request('GET', $uri);
        $keys = is_array($document) ? ($document['keys'] ?? null) : null;
        if (!is_array($keys) || $keys === []) {
            throw new RuntimeException('โหลดกุญแจสาธารณะของ ThaiD ไม่สำเร็จ');
        }
        self::$jwks = $keys;
        self::$jwksAt = time();
        return $keys;
    }

    /** @return array<string,mixed> */
    private static function discovery(): array {
        if (self::$discovery !== null && (time() - self::$discoveryAt) < self::CACHE_TTL) {
            return self::$discovery;
        }
        $document = self::request('GET', THAID_ISSUER . '/.well-known/openid-configuration');
        // Guessing endpoints from the issuer would silently send credentials to the
        // wrong host if DOPA ever moves them, so treat a failed discovery as fatal.
        if (!is_array($document) || empty($document['authorization_endpoint']) || empty($document['token_endpoint'])) {
            throw new RuntimeException('โหลดค่าตั้งต้น (discovery) ของ ThaiD ไม่สำเร็จ');
        }
        self::$discovery = $document;
        self::$discoveryAt = time();
        return $document;
    }

    /** @return array<string,mixed> */
    private static function post(string $url, array $form): array {
        $response = self::request('POST', $url, http_build_query($form), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        return is_array($response) ? $response : [];
    }

    /** @return array<string,mixed>|null */
    private static function request(string $method, string $url, ?string $body = null, array $headers = []) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ต้องเปิดส่วนขยาย cURL เพื่อเชื่อมต่อ ThaiD');
        }

        $headers[] = 'Accept: application/json';
        if (defined('THAID_API_KEY') && THAID_API_KEY !== '') {
            $headers[] = THAID_API_KEY_HEADER . ': ' . THAID_API_KEY;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            // DOPA accepts client_secret_basic and client_secret_post; Basic keeps
            // the secret out of the request body.
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = THAID_CLIENT_ID . ':' . THAID_CLIENT_SECRET;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('เชื่อมต่อ ThaiD ไม่สำเร็จ: ' . $error);
        }
        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 && !is_array($decoded)) {
            throw new RuntimeException('ThaiD ตอบกลับสถานะ ' . $status);
        }
        return is_array($decoded) ? $decoded : null;
    }

    private static function assertConfigured(): void {
        if (!self::isConfigured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า ThaiD (ต้องมี THAID_CLIENT_ID และ THAID_CLIENT_SECRET)');
        }
    }
}
