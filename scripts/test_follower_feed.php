<?php
// scripts/test_follower_feed.php — ทดสอบ feed แจ้งเตือนผู้ติดตาม (CLI only)
if (php_sapi_name() !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/FollowerFeedService.php';

$seller = $argv[1] ?? 'test1';
$productId = (int) ($argv[2] ?? 1);
$name = $argv[3] ?? 'สินค้าทดสอบ Follower Feed';
$price = (float) ($argv[4] ?? 199);

echo "Notify followers of {$seller} (product #{$productId})...\n";
$count = FollowerFeedService::notifyNewListing($conn, $seller, $productId, $name, $price);
echo "Notified: {$count} follower(s)\n";

$res = $conn->query("SELECT user_name, type, title, message, link_url, is_read FROM notifications WHERE type = 'follow' ORDER BY id DESC LIMIT 5");
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
}
