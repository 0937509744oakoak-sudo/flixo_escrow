<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/NotificationService.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$me = $_SESSION['user'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

function formatNotificationTime(string $createdAt): string
{
    $ts = strtotime($createdAt);
    if (!$ts) {
        return '';
    }
    return (date('Ymd') === date('Ymd', $ts)) ? date('H:i', $ts) : date('d/m/Y', $ts);
}

function formatNotificationRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => $row['type'] ?? 'system',
        'title' => $row['title'] ?? '',
        'message' => $row['message'] ?? '',
        'url' => $row['link_url'] ?? '',
        'icon' => $row['icon'] ?? '🔔',
        'is_read' => !empty($row['is_read']),
        'time' => formatNotificationTime($row['created_at'] ?? ''),
    ];
}

switch ($action) {
    case 'unread_count':
        echo json_encode([
            'status' => 'success',
            'count' => NotificationService::getUnreadCount($conn, $me),
            'chat_count' => NotificationService::getUnreadChatCount($conn, $me),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'list':
        $rows = NotificationService::getRecent($conn, $me, 20);
        echo json_encode([
            'status' => 'success',
            'notifications' => array_map('formatNotificationRow', $rows),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'latest_unread':
        $row = NotificationService::getLatestUnread($conn, $me);
        if (!$row) {
            echo json_encode(['status' => 'empty'], JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode([
            'status' => 'success',
            'notification' => [
                'title' => $row['title'] !== '' ? $row['title'] : 'FLIXO',
                'body' => $row['message'],
                'url' => $row['link_url'] ?: (rtrim(BASE_URL, '/') . '/dashboard.php'),
                'icon' => $row['icon'] ?: '🔔',
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'mark_read':
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $ok = $id > 0 && NotificationService::markRead($conn, $me, $id);
        echo json_encode(['status' => $ok ? 'success' : 'error'], JSON_UNESCAPED_UNICODE);
        break;

    case 'mark_all_read':
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        $ok = NotificationService::markAllRead($conn, $me);
        echo json_encode(['status' => $ok ? 'success' : 'error'], JSON_UNESCAPED_UNICODE);
        break;

    case 'save_push_subscription':
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        $endpoint = trim($_POST['endpoint'] ?? '');
        $p256dh = trim($_POST['p256dh'] ?? '');
        $auth = trim($_POST['auth'] ?? '');
        $userAgent = substr(trim($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ok = NotificationService::savePushSubscription($conn, $me, $endpoint, $p256dh, $auth, $userAgent);
        echo json_encode(['status' => $ok ? 'success' : 'error'], JSON_UNESCAPED_UNICODE);
        break;

    case 'remove_push_subscription':
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        $endpoint = trim($_POST['endpoint'] ?? '');
        $ok = NotificationService::removePushSubscription($conn, $me, $endpoint);
        echo json_encode(['status' => $ok ? 'success' : 'error'], JSON_UNESCAPED_UNICODE);
        break;

    case 'get_preferences':
        $prefs = NotificationService::getPreferences($conn, $me);
        echo json_encode([
            'status' => 'success',
            'preferences' => $prefs,
            'vapid_public_key' => defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '',
            'push_configured' => WebPush::isConfigured(),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'update_preferences':
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        $emailEnabled = !empty($_POST['email_enabled']);
        $pushEnabled = !empty($_POST['push_enabled']);
        $marketingEnabled = !empty($_POST['marketing_enabled']);
        $ok = NotificationService::updatePreferences($conn, $me, $emailEnabled, $pushEnabled, $marketingEnabled);
        echo json_encode(['status' => $ok ? 'success' : 'error'], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
}
