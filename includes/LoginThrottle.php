<?php
/**
 * Rate limiting for credential endpoints (login, password reset request).
 *
 * Tracked per account and per IP: the account limit stops someone grinding one
 * password list against a known user, the IP limit stops them spreading the
 * same attempt across many accounts.
 */
class LoginThrottle {
    private const WINDOW_SECONDS = 900;      // 15 minutes
    private const MAX_PER_IDENTIFIER = 5;
    private const MAX_PER_IP = 30;

    /**
     * @return array{allowed: bool, retry_after: int} retry_after is in seconds
     */
    public static function check(mysqli $conn, string $action, string $identifier, string $ip): array {
        $identifier = self::normalise($identifier);

        $stmt = $conn->prepare(
            'SELECT
                SUM(identifier = ?) AS by_identifier,
                SUM(ip = ?) AS by_ip,
                MIN(created_at) AS oldest
             FROM login_attempts
             WHERE action = ?
               AND successful = 0
               AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
               AND (identifier = ? OR ip = ?)'
        );
        if (!$stmt) {
            // The table may not exist yet on a database that has not run
            // migration 027. Failing open keeps login working; the migration
            // is what turns protection on.
            return ['allowed' => true, 'retry_after' => 0];
        }
        $window = self::WINDOW_SECONDS;
        $stmt->bind_param('sssiss', $identifier, $ip, $action, $window, $identifier, $ip);
        if (!$stmt->execute()) {
            return ['allowed' => true, 'retry_after' => 0];
        }
        $row = $stmt->get_result()->fetch_assoc() ?: [];

        $byIdentifier = (int) ($row['by_identifier'] ?? 0);
        $byIp = (int) ($row['by_ip'] ?? 0);
        if ($byIdentifier < self::MAX_PER_IDENTIFIER && $byIp < self::MAX_PER_IP) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $oldest = $row['oldest'] ?? null;
        $retryAfter = $oldest
            ? max(0, self::WINDOW_SECONDS - (time() - strtotime((string) $oldest)))
            : self::WINDOW_SECONDS;

        return ['allowed' => false, 'retry_after' => $retryAfter];
    }

    public static function record(mysqli $conn, string $action, string $identifier, string $ip, bool $successful): void {
        $stmt = $conn->prepare(
            'INSERT INTO login_attempts (action, identifier, ip, successful) VALUES (?, ?, ?, ?)'
        );
        if (!$stmt) {
            return;
        }
        $identifier = self::normalise($identifier);
        $flag = $successful ? 1 : 0;
        $stmt->bind_param('sssi', $action, $identifier, $ip, $flag);
        $stmt->execute();

        if ($successful) {
            // A correct password clears the account's strikes so a user who
            // fumbled a few times is not locked out afterwards.
            $clear = $conn->prepare('DELETE FROM login_attempts WHERE action = ? AND identifier = ? AND successful = 0');
            if ($clear) {
                $clear->bind_param('ss', $action, $identifier);
                $clear->execute();
            }
        }

        // Opportunistic cleanup, roughly once every 50 writes.
        if (random_int(1, 50) === 1) {
            $conn->query('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    }

    public static function clientIp(): string {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function retryMessage(int $retryAfter): string {
        $minutes = max(1, (int) ceil($retryAfter / 60));
        return "พยายามเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารออีก {$minutes} นาทีแล้วลองใหม่";
    }

    private static function normalise(string $identifier): string {
        return mb_strtolower(trim($identifier));
    }
}
