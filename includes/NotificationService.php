<?php
/**
 * FLIXO Notifications — single entry point for in-app + email + push delivery.
 *
 * Replaces the old approach of faking notifications as support-chat messages.
 * `messages` / support_chat.php remain untouched for genuine human support
 * and peer-to-peer chat.
 */

require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/WebPush.php';

class NotificationService
{
    /**
     * Create + dispatch a notification across enabled channels.
     *
     * @param array{type?:string,title?:string,message:string,url?:string,icon?:string} $opts
     */
    public static function notifyUser(mysqli $conn, string $username, array $opts): int
    {
        $username = trim($username);
        $message = trim((string) ($opts['message'] ?? ''));
        if ($username === '' || $message === '') {
            return 0;
        }

        $url = trim((string) ($opts['url'] ?? ''));
        $meta = self::inferMeta($url);
        $type = trim((string) ($opts['type'] ?? '')) ?: $meta['type'];
        $title = trim((string) ($opts['title'] ?? '')) ?: $meta['title'];
        $icon = trim((string) ($opts['icon'] ?? '')) ?: $meta['icon'];

        $linkUrl = $url !== '' ? self::toAbsoluteUrl($url) : '';

        $stmt = $conn->prepare(
            'INSERT INTO notifications (user_name, type, title, message, link_url, icon, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())'
        );
        if (!$stmt) {
            error_log('NotificationService: insert prepare failed — ' . $conn->error);
            return 0;
        }
        $stmt->bind_param('ssssss', $username, $type, $title, $message, $linkUrl, $icon);
        $stmt->execute();
        $notificationId = (int) $conn->insert_id;

        $channels = ['in_app' => true];
        $prefs = self::getPreferences($conn, $username);

        if ($prefs['email_enabled']) {
            $channels['email'] = self::sendEmailChannel($conn, $username, $title, $message, $linkUrl);
        }

        if ($prefs['push_enabled'] && WebPush::isConfigured()) {
            $channels['push'] = self::sendPushChannel($conn, $username);
        }

        self::updateChannels($conn, $notificationId, $channels);

        return $notificationId;
    }

    private static function inferMeta(string $url): array
    {
        if (strpos($url, 'dispute_detail.php') !== false) {
            return ['type' => 'dispute', 'title' => 'ข้อพิพาท', 'icon' => '⚠️'];
        }
        if (strpos($url, 'earnings.php') !== false) {
            return ['type' => 'payout', 'title' => 'รายได้', 'icon' => '💰'];
        }
        if (strpos($url, 'buy_product.php') !== false || strpos($url, 'dashboard.php') !== false) {
            return ['type' => 'order', 'title' => 'ออเดอร์ของคุณ', 'icon' => '📦'];
        }
        if (strpos($url, 'kyc') !== false) {
            return ['type' => 'ekyc', 'title' => 'ยืนยันตัวตน', 'icon' => '🪪'];
        }
        return ['type' => 'system', 'title' => 'ข้อความจาก FLIXO', 'icon' => '🔔'];
    }

    private static function toAbsoluteUrl(string $url): string
    {
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        return defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' . ltrim($url, '/') : $url;
    }

    private static function sendEmailChannel(mysqli $conn, string $username, string $title, string $message, string $linkUrl): bool
    {
        $stmt = $conn->prepare('SELECT email FROM users WHERE username = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user || empty($user['email'])) {
            return false;
        }

        $subject = $title !== '' ? $title : 'ข้อความจาก FLIXO';
        $body = $message;
        if ($linkUrl !== '') {
            $body .= "\n\nดูรายละเอียด: " . $linkUrl;
        }

        $status = 'PENDING';
        $log = $conn->prepare('INSERT INTO email_event_logs (user_name, recipient_email, subject, status) VALUES (?, ?, ?, ?)');
        if ($log) {
            $log->bind_param('ssss', $username, $user['email'], $subject, $status);
            $log->execute();
        }

        $emailService = new EmailService();
        return $emailService->send($user['email'], $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
    }

    private static function sendPushChannel(mysqli $conn, string $username): bool
    {
        $stmt = $conn->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_name = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();

        $anySent = false;
        while ($row = $result->fetch_assoc()) {
            $outcome = WebPush::send([
                'endpoint' => $row['endpoint'],
                'p256dh' => $row['p256dh'],
                'auth' => $row['auth'],
            ]);
            if ($outcome === 'sent') {
                $anySent = true;
                $upd = $conn->prepare('UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?');
                if ($upd) {
                    $upd->bind_param('i', $row['id']);
                    $upd->execute();
                }
            } elseif ($outcome === 'gone') {
                $del = $conn->prepare('DELETE FROM push_subscriptions WHERE id = ?');
                if ($del) {
                    $del->bind_param('i', $row['id']);
                    $del->execute();
                }
            }
        }

        return $anySent;
    }

    private static function updateChannels(mysqli $conn, int $notificationId, array $channels): void
    {
        if ($notificationId <= 0) {
            return;
        }
        $json = json_encode($channels, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare('UPDATE notifications SET channels = ? WHERE id = ?');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('si', $json, $notificationId);
        $stmt->execute();
    }

    public static function getUnreadCount(mysqli $conn, string $username): int
    {
        if ($username === '') {
            return 0;
        }
        $stmt = $conn->prepare('SELECT COUNT(*) AS unread FROM notifications WHERE user_name = ? AND is_read = 0');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['unread'] ?? 0);
    }

    /**
     * จำนวนแจ้งเตือน "แชท" ที่ยังไม่อ่าน (peer-to-peer) — ใช้กับจุดแดงบนปุ่มห้องคุยคู่สัญญา
     */
    public static function getUnreadChatCount(mysqli $conn, string $username): int
    {
        if ($username === '') {
            return 0;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS unread FROM notifications WHERE user_name = ? AND type = 'chat' AND is_read = 0");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        return (int) ($stmt->get_result()->fetch_assoc()['unread'] ?? 0);
    }

    public static function getRecent(mysqli $conn, string $username, int $limit = 20): array
    {
        if ($username === '') {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare(
            'SELECT id, type, title, message, link_url, icon, is_read, created_at
             FROM notifications WHERE user_name = ? ORDER BY created_at DESC LIMIT ?'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('si', $username, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function getLatestUnread(mysqli $conn, string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        $stmt = $conn->prepare(
            'SELECT id, type, title, message, link_url, icon, created_at
             FROM notifications WHERE user_name = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public static function markRead(mysqli $conn, string $username, int $id): bool
    {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_name = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('is', $id, $username);
        return $stmt->execute();
    }

    public static function markAllRead(mysqli $conn, string $username): bool
    {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_name = ? AND is_read = 0');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $username);
        return $stmt->execute();
    }

    public static function savePushSubscription(mysqli $conn, string $username, string $endpoint, string $p256dh, string $auth, string $userAgent = ''): bool
    {
        $endpoint = trim($endpoint);
        if ($username === '' || $endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }
        $endpointHash = hash('sha256', $endpoint);

        $stmt = $conn->prepare(
            'INSERT INTO push_subscriptions (user_name, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE user_name = VALUES(user_name), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)'
        );
        if (!$stmt) {
            error_log('NotificationService: savePushSubscription prepare failed — ' . $conn->error);
            return false;
        }
        $stmt->bind_param('ssssss', $username, $endpoint, $endpointHash, $p256dh, $auth, $userAgent);
        return $stmt->execute();
    }

    public static function removePushSubscription(mysqli $conn, string $username, string $endpoint): bool
    {
        $endpointHash = hash('sha256', trim($endpoint));
        $stmt = $conn->prepare('DELETE FROM push_subscriptions WHERE user_name = ? AND endpoint_hash = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $username, $endpointHash);
        return $stmt->execute();
    }

    public static function getPreferences(mysqli $conn, string $username): array
    {
        $defaults = ['email_enabled' => true, 'push_enabled' => true, 'marketing_enabled' => true];
        if ($username === '') {
            return $defaults;
        }

        $stmt = $conn->prepare('SELECT email_enabled, push_enabled, marketing_enabled FROM notification_preferences WHERE user_name = ? LIMIT 1');
        if (!$stmt) {
            return $defaults;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return $defaults;
        }

        return [
            'email_enabled' => (bool) $row['email_enabled'],
            'push_enabled' => (bool) $row['push_enabled'],
            'marketing_enabled' => (bool) $row['marketing_enabled'],
        ];
    }

    public static function updatePreferences(mysqli $conn, string $username, bool $emailEnabled, bool $pushEnabled, bool $marketingEnabled = true): bool
    {
        if ($username === '') {
            return false;
        }
        $stmt = $conn->prepare(
            'INSERT INTO notification_preferences (user_name, email_enabled, push_enabled, marketing_enabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), push_enabled = VALUES(push_enabled), marketing_enabled = VALUES(marketing_enabled)'
        );
        if (!$stmt) {
            return false;
        }
        $emailInt = $emailEnabled ? 1 : 0;
        $pushInt = $pushEnabled ? 1 : 0;
        $marketingInt = $marketingEnabled ? 1 : 0;
        $stmt->bind_param('siii', $username, $emailInt, $pushInt, $marketingInt);
        return $stmt->execute();
    }
}
