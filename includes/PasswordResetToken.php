<?php
/**
 * Stateless password-reset tokens (no token table required).
 *
 * The user's current password hash is mixed into the signing key, so a token
 * dies the moment the password changes — that gives single use, and reuse of an
 * intercepted link, without any storage.
 */
class PasswordResetToken {
    private const TTL_SECONDS = 3600;

    /**
     * Tokens are only as strong as ENCRYPTION_KEY. Local dev is allowed a weak
     * derived fallback so the flow stays testable without a configured key;
     * every other environment must set one or password reset stays disabled.
     */
    private static function keyMaterial(): ?string {
        $key = defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '';
        if ($key !== '') {
            return $key;
        }
        if (defined('APP_ENV') && APP_ENV === 'local') {
            return 'flixo-local-dev|' . (defined('DB_NAME') ? DB_NAME : 'flixo');
        }
        return null;
    }

    public static function isAvailable(): bool {
        return self::keyMaterial() !== null;
    }

    private static function signingKey(string $passwordHash): string {
        return hash_hmac('sha256', 'flixo-reset|' . $passwordHash, (string) self::keyMaterial());
    }

    public static function issue(int $userId, string $passwordHash): ?string {
        if (!self::isAvailable() || $userId <= 0 || $passwordHash === '') {
            return null;
        }
        $payload = $userId . '|' . (time() + self::TTL_SECONDS);
        $sig = hash_hmac('sha256', $payload, self::signingKey($passwordHash));
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /**
     * @param callable(int):?string $passwordHashLookup resolves a user id to its
     *                                                  current password hash
     * @return array{user_id:int, exp:int}|null
     */
    public static function parse(string $token, callable $passwordHashLookup): ?array {
        if (!self::isAvailable() || $token === '') {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $parts = explode('|', $raw);
        if (count($parts) !== 3) {
            return null;
        }
        [$userId, $exp, $sig] = $parts;
        if (!ctype_digit($userId) || !ctype_digit($exp) || (int) $exp < time()) {
            return null;
        }

        $passwordHash = $passwordHashLookup((int) $userId);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $userId . '|' . $exp, self::signingKey($passwordHash));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return ['user_id' => (int) $userId, 'exp' => (int) $exp];
    }
}
