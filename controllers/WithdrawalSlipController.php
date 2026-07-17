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
$isStaff = Rbac::can($role, 'withdrawal.manage');
if (!$isOwner && !$isStaff) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูสลิปนี้');
}

$rawStored = (string) ($req['payout_slip'] ?? '');
$filename = basename($rawStored);
if ($filename === '' || preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:48px;">';
    echo '<h1>ยังไม่มีสลิปการโอน</h1><p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
    exit;
}

$mimeFromExt = static function (string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        default => 'application/octet-stream',
    };
};

// 1) Prefer local disk (legacy files / local dev).
$filepath = withdrawalSlipPath($filename);
if ($filepath !== null) {
    header('Content-Type: ' . $mimeFromExt($filepath));
    header('Content-Length: ' . (string) filesize($filepath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($filepath);
    exit;
}

// 2) Not on disk → stream privately from Cloud Object Storage (R2/S3).
require_once __DIR__ . '/../includes/ObjectStorage.php';
$storage = new ObjectStorage();
if ($storage->isConfigured()) {
    $key = $storage->keyFromStored($rawStored, 'withdrawal_slips');
    $bytes = $storage->get($key);
    if ($bytes !== null) {
        header('Content-Type: ' . $mimeFromExt($filename));
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        echo $bytes;
        exit;
    }
}

// 3) Neither local nor cloud.
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ไม่พบสลิป</title></head>';
echo '<body style="font-family:sans-serif;text-align:center;padding:48px;">';
echo '<h1>ไฟล์สลิปไม่พบบนเซิร์ฟเวอร์</h1><p><a href="javascript:history.back()">ย้อนกลับ</a></p></body></html>';
exit;
