<?php
/**
 * Serve payment slip images to authorized users (buyer, seller, staff).
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/Rbac.php';
require_once __DIR__ . '/../models/Order.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('กรุณาเข้าสู่ระบบ');
}

$me = $_SESSION['user'];
$role = strtolower((string) ($_SESSION['role'] ?? 'user'));
$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    exit('คำขอไม่ถูกต้อง');
}

$orderModel = new Order($conn);
$order = $orderModel->findById($orderId);
if (!$order) {
    http_response_code(404);
    exit('ไม่พบออเดอร์');
}

$isParty = ($me === ($order['buyer_name'] ?? '')) || ($me === ($order['seller_name'] ?? ''));
$isStaff = Rbac::isStaff($role);
if (!$isParty && !$isStaff) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูสลิปนี้');
}

$filename = basename((string) ($order['slip_image'] ?? ''));
if ($filename === '' || preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head><body style="font-family:sans-serif;text-align:center;padding:48px;">';
    echo '<h1>ไม่พบไฟล์สลิป</h1><p>ออเดอร์นี้ยังไม่มีไฟล์สลิปในระบบ</p>';
    echo '<p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
    exit;
}

$filepath = orderSlipPath($filename);
if ($filepath === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    $uploadsRoot = htmlspecialchars(flixoUploadsRoot(), ENT_QUOTES, 'UTF-8');
    $vol = htmlspecialchars((string) (getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: '(ไม่ได้ตั้งค่า)'), ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:48px;background:#f4f6f8;">';
    echo '<div style="background:#fff;display:inline-block;padding:32px;border-radius:12px;max-width:520px;box-shadow:0 4px 16px rgba(0,0,0,.08);text-align:left;">';
    echo '<h1 style="margin:0 0 12px;font-size:20px;text-align:center;">ไฟล์สลิปไม่พบบนเซิร์ฟเวอร์</h1>';
    echo '<p style="color:#555;">ไฟล์เก่าอาจหายหลัง deploy หากยังไม่ได้ผูก Volume ให้ถูกต้อง</p>';
    echo '<ul style="color:#666;font-size:13px;line-height:1.6;">';
    echo '<li>Mount Path ที่ควรใช้: <code>/var/www/html/uploads</code></li>';
    echo '<li>RAILWAY_VOLUME_MOUNT_PATH ตอนนี้: <code>' . $vol . '</code></li>';
    echo '<li>Uploads root ที่ระบบใช้: <code>' . $uploadsRoot . '</code></li>';
    echo '<li>ตัวแปรแนะนำ: <code>RAILWAY_RUN_UID=0</code></li>';
    echo '</ul>';
    if ($me === ($order['buyer_name'] ?? '')) {
        $payUrl = htmlspecialchars(rtrim(BASE_URL, '/') . '/buy_product.php?order_id=' . $orderId, ENT_QUOTES, 'UTF-8');
        echo '<p style="text-align:center;"><a href="' . $payUrl . '" style="display:inline-block;padding:12px 18px;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;">อัปโหลดสลิปใหม่</a></p>';
    }
    echo '<p style="text-align:center;margin-top:12px;"><a href="javascript:history.back()">ย้อนกลับ</a></p>';
    echo '</div></body></html>';
    exit;
}

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filepath);
exit;
