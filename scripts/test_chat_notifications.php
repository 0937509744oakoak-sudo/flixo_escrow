<?php
// CLI test: chat notification + anti-spam throttle
// php scripts/test_chat_notifications.php <sender> <receiver>
if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

$sender = $argv[1] ?? 'test2';
$receiver = $argv[2] ?? 'test1';

function countChatNotifs(mysqli $conn, string $user): int {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_name = ? AND type = 'chat' AND is_read = 0");
    $stmt->bind_param('s', $user);
    $stmt->execute();
    return (int) $stmt->get_result()->fetch_assoc()['c'];
}

echo "unread chat notifs ({$receiver}) before: " . countChatNotifs($conn, $receiver) . "\n";

flixo_notify_chat_message($conn, $sender, $receiver, 'สวัสดีครับ สนใจสินค้า');
echo "after msg 1: " . countChatNotifs($conn, $receiver) . " (ควร +1)\n";

flixo_notify_chat_message($conn, $sender, $receiver, 'ยังอยู่ไหมครับ');
flixo_notify_chat_message($conn, $sender, $receiver, 'ต่อรองราคาได้ไหม');
echo "after msg 2-3 (burst): " . countChatNotifs($conn, $receiver) . " (ต้องเท่าเดิม — กันสแปม)\n";

flixo_mark_chat_notifications_read($conn, $receiver, $sender);
echo "after mark read: " . countChatNotifs($conn, $receiver) . " (ควรเป็น 0)\n";

flixo_notify_chat_message($conn, $sender, $receiver, 'ข้อความใหม่หลังอ่านแล้ว');
echo "after msg 4: " . countChatNotifs($conn, $receiver) . " (ควร +1 อีกครั้ง)\n";

$res = $conn->query("SELECT user_name, type, title, message, link_url, is_read FROM notifications WHERE type = 'chat' ORDER BY id DESC LIMIT 3");
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
