<?php
// scripts/test_slip_upload.php
// Usage: php scripts/test_slip_upload.php [order_id] [username]

require_once __DIR__ . '/../includes/db.php';

$order_id = isset($argv[1]) ? (int)$argv[1] : 0;
$username = isset($argv[2]) ? trim($argv[2]) : 'test_user';
$ip = '127.0.0.1';

if ($order_id <= 0) {
    $row = $conn->query("SELECT id FROM orders ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $order_id = $row['id'] ?? 0;
}

if ($order_id <= 0) {
    echo "No order found in database. Please create at least one order before running this test.\n";
    exit(1);
}

$stmt = $conn->prepare("INSERT INTO slip_upload_attempts (order_id, username, ip) VALUES (?, ?, ?)");
$stmt->bind_param('iss', $order_id, $username, $ip);
$success = $stmt->execute();
$insertId = $stmt->insert_id;
$stmt->close();

if (!$success) {
    echo "Failed to insert test slip upload attempt: " . $conn->error . "\n";
    exit(1);
}

$result = $conn->query("SELECT id, order_id, username, ip, created_at FROM slip_upload_attempts WHERE id = $insertId");
$entry = $result->fetch_assoc();

echo "Test slip upload attempt inserted successfully:\n";
echo "  ID: " . $entry['id'] . "\n";
echo "  Order ID: " . $entry['order_id'] . "\n";
echo "  Username: " . $entry['username'] . "\n";
echo "  IP: " . $entry['ip'] . "\n";
echo "  Created At: " . $entry['created_at'] . "\n";

echo "\nRecent attempts for this user/IP in last 10 minutes:\n";
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM slip_upload_attempts WHERE (username = ? OR ip = ?) AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$stmt->bind_param('ss', $username, $ip);
$stmt->execute();
$countResult = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo "  Count: " . ($countResult['cnt'] ?? 0) . "\n";

echo "\nหลังจากรันสคริปต์นี้ คุณสามารถเปิดหน้า admin/logs.php เพื่อดูรายการที่เพิ่มขึ้นได้ทันที.\n";
