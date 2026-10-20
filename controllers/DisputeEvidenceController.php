<?php
/**
 * Serve dispute evidence to authorised users (buyer, seller, staff).
 *
 * Evidence routinely contains ID cards, receipts and delivery addresses. It
 * used to be linked as a plain uploads/ path with a predictable filename, so
 * anyone could walk other people's disputes; this endpoint is the only way in.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/Rbac.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../models/Order.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('กรุณาเข้าสู่ระบบ');
}

$me = $_SESSION['user'];
$evidenceId = (int) ($_GET['evidence_id'] ?? 0);
if ($evidenceId <= 0) {
    http_response_code(400);
    exit('คำขอไม่ถูกต้อง');
}

$stmt = $conn->prepare(
    'SELECT e.file_path, e.dispute_id, d.order_id
     FROM dispute_evidences e
     JOIN disputes d ON d.id = e.dispute_id
     WHERE e.id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $evidenceId);
$stmt->execute();
$evidence = $stmt->get_result()->fetch_assoc();
if (!$evidence) {
    http_response_code(404);
    exit('ไม่พบหลักฐาน');
}

$orderModel = new Order($conn);
$order = $orderModel->findById((int) $evidence['order_id']);
if (!$order) {
    http_response_code(404);
    exit('ไม่พบออเดอร์ของข้อพิพาทนี้');
}

// Resolve the role from the database rather than the session, so a revoked
// staff role takes effect immediately.
$role = Rbac::roleOf($conn, $me);
$isParty = ($me === ($order['buyer_name'] ?? '')) || ($me === ($order['seller_name'] ?? ''));
if (!$isParty && !Rbac::can($role, 'dispute.resolve')) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูหลักฐานนี้');
}

$stored = (string) $evidence['file_path'];
$filename = basename($stored);

$mimeFromExt = static function (string $name): string {
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        default => 'application/octet-stream',
    };
};

$sendHeaders = static function (string $contentType, int $length) use ($filename): void {
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $length);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    // Uploaded bytes are untrusted: never let the browser render them as a
    // document in our origin.
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
};

// 1) Local disk (legacy rows and local development).
$path = disputeEvidencePath($stored);
if ($path !== null) {
    $sendHeaders($mimeFromExt($path), (int) filesize($path));
    readfile($path);
    exit;
}

// 2) Otherwise stream from object storage. Authorisation is already done, so
//    we act as a trusted proxy rather than handing out a public URL.
require_once __DIR__ . '/../includes/ObjectStorage.php';
$storage = new ObjectStorage();
if ($storage->isConfigured()) {
    $bytes = $storage->get($storage->keyFromStored($stored, 'disputes'));
    if ($bytes !== null) {
        $sendHeaders($mimeFromExt($filename), strlen($bytes));
        echo $bytes;
        exit;
    }
}

http_response_code(404);
exit('ไม่พบไฟล์หลักฐานบนเซิร์ฟเวอร์');
