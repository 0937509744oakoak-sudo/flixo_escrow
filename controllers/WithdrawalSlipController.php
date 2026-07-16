<?php
/**
 * Serve withdrawal payout slips to the withdrawal owner or staff.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/Rbac.php';
require_once __DIR__ . '/../models/Wallet.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('กรุณาเข้าสู่ระบบ');
}

$me = $_SESSION['user'];
$role = Rbac::roleOf($conn, $me);
$requestId = (int) ($_GET['request_id'] ?? 0);

if ($requestId <= 0) {
    http_response_code(400);
    exit('คำขอไม่ถูกต้อง');
}

$walletModel = new Wallet($conn);
$req = $walletModel->findWithdrawalById($requestId);
if (!$req) {
    http_response_code(404);
    exit('ไม่พบคำขอถอน');
}

$isOwner = ($me === ($req['username'] ?? ''));
$isStaff = Rbac::can($role, 'withdrawal.manage') || Rbac::isStaff($role);
if (!$isOwner && !$isStaff) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูสลิปนี้');
}

$filename = basename((string) ($req['payout_slip'] ?? ''));
if ($filename === '' || preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:48px;">';
    echo '<h1>ยังไม่มีสลิปการโอน</h1><p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
    exit;
}

$filepath = withdrawalSlipPath($filename);
if ($filepath === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:48px;">';
    echo '<h1>ไฟล์สลิปไม่พบบนเซิร์ฟเวอร์</h1><p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
    exit;
}

$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'pdf' => 'application/pdf',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filepath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit;
