<?php
// includes/order_helper.php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/OrderStateMachine.php';
require_once __DIR__ . '/EmailService.php';

/**
 * Persistent uploads root (Railway Volume when mounted).
 */
function flixoUploadsRoot(): string {
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $candidates = [];
    foreach (['UPLOADS_PATH', 'RAILWAY_VOLUME_MOUNT_PATH'] as $envKey) {
        $val = getenv($envKey);
        if (is_string($val) && trim($val) !== '') {
            $candidates[] = rtrim(trim($val), "/\\");
        }
    }
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';

    foreach ($candidates as $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        if (is_dir($path) && is_writable($path)) {
            $root = $path;
            return $root;
        }
    }

    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    @mkdir($root, 0755, true);
    return $root;
}

/**
 * Absolute path to an uploads subdirectory, with trailing directory separator.
 */
function flixoUploadsSubdir(string $sub): string {
    $sub = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sub), DIRECTORY_SEPARATOR);
    $dir = flixoUploadsRoot() . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR;
}

/**
 * URL for viewing an order payment slip (authorized PHP endpoint).
 */
function orderSlipUrl(?string $slipImage, ?int $orderId = null): string {
    if ($orderId !== null && $orderId > 0) {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        return $base . '/controllers/SlipController.php?order_id=' . (int) $orderId;
    }

    $slipImage = trim((string) $slipImage);
    if ($slipImage === '') {
        return '';
    }
    $filename = basename($slipImage);
    $base = defined('UPLOADS_URL')
        ? rtrim(UPLOADS_URL, '/')
        : (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/uploads' : 'uploads');
    return $base . '/slips/' . rawurlencode($filename);
}

/**
 * Absolute filesystem path for a slip filename, or null if missing.
 */
function orderSlipPath(?string $slipImage): ?string {
    $filename = basename(trim((string) $slipImage));
    if ($filename === '') {
        return null;
    }
    $dir = rtrim(flixoUploadsSubdir('slips'), "/\\");
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $realPath;
}

/**
 * URL for viewing a dispute evidence file. Evidence contains ID documents,
 * receipts and addresses, so it is never linked directly — the controller
 * checks that the caller is a party to the dispute (or staff) first.
 */
function disputeEvidenceUrl(int $evidenceId): string {
    if ($evidenceId <= 0) {
        return '';
    }
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return $base . '/controllers/DisputeEvidenceController.php?evidence_id=' . $evidenceId;
}

/**
 * Absolute filesystem path for a stored dispute evidence file, or null.
 * Accepts both the current bare filename and the legacy "uploads/disputes/x"
 * relative path that older rows still hold.
 */
function disputeEvidencePath(?string $stored): ?string {
    $filename = basename(trim((string) $stored));
    if ($filename === '') {
        return null;
    }
    $dir = rtrim(flixoUploadsSubdir('disputes'), "/\\");
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $realPath;
}

/**
 * URL for viewing an admin payout slip on a withdrawal request.
 */
function withdrawalSlipUrl(int $requestId): string {
    if ($requestId <= 0) {
        return '';
    }
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return $base . '/controllers/WithdrawalSlipController.php?request_id=' . $requestId;
}

/**
 * Absolute filesystem path for a withdrawal payout slip, or null if missing.
 */
function withdrawalSlipPath(?string $slipImage): ?string {
    $filename = basename(trim((string) $slipImage));
    if ($filename === '') {
        return null;
    }
    $dir = rtrim(flixoUploadsSubdir('withdrawal_slips'), "/\\");
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        return null;
    }
    $realDir = realpath($dir);
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $realPath;
}

/**
 * Thunder v2 returns amountInSlip, with rawSlip.amount.amount as a fallback.
 * Legacy EasySlip payloads are retained for historical callers/data fixtures.
 * Casting an array to float in PHP yields 1.0 — never do that for amount data.
 */
function extractSlipAmount(array $slipData): float {
    $raw = $slipData['amountInSlip'] ?? $slipData['rawSlip']['amount'] ?? $slipData['amount'] ?? null;

    if (is_array($raw)) {
        if (isset($raw['amount']) && is_numeric($raw['amount'])) {
            return round((float) $raw['amount'], 2);
        }
        if (isset($raw['local']['amount']) && is_numeric($raw['local']['amount'])) {
            return round((float) $raw['local']['amount'], 2);
        }
        return 0.0;
    }

    if (is_numeric($raw)) {
        return round((float) $raw, 2);
    }

    return 0.0;
}

/**
 * ตรวจสอบว่าเป็นเจ้าของออเดอร์หรือไม่
 */
function getOrderIfOwner($conn, $order_id, $user, $role_required = 'buyer') {
    $sql = "SELECT * FROM orders WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if (!$order) return false;

    if ($role_required == 'buyer' && $order['buyer_name'] !== $user) return false;
    if ($role_required == 'seller' && $order['seller_name'] !== $user) return false;

    return $order;
}

/**
 * ตรวจว่าออเดอร์ยังชำระเงินได้หรือไม่
 * @return array|null null = ดำเนินการต่อได้, array = ตอบ JSON แล้วจบ
 */
function guardOrderPaymentAction(array $order): ?array {
    if ($order['status'] === OrderStateMachine::PAID) {
        return [
            'status' => 'success',
            'message' => 'ออเดอร์นี้ชำระเงินแล้ว รอผู้ขายจัดส่งสินค้า',
            'already_paid' => true,
        ];
    }
    if ($order['status'] === OrderStateMachine::WAITING_VERIFY) {
        // Allow replacing slip (file may be missing after redeploy / volume issues).
        return null;
    }
    if ($order['status'] !== OrderStateMachine::PENDING_PAYMENT) {
        return [
            'status' => 'error',
            'message' => 'ออเดอร์นี้ไม่สามารถชำระเงินได้ (สถานะ: ' . OrderStateMachine::getLabel($order['status']) . ')',
        ];
    }
    return null;
}

/**
 * แจ้งผู้ใช้ผ่านระบบ Notifications (in-app + email + push)
 *
 * Kept the original signature so every existing call site
 * (OrderController, DisputeController, DisputeFlowService, CourierService,
 * omise_helper, webhook_ekyc, admin/escrow_list.php) needs no changes.
 * Support chat is no longer used as a notification channel — it's reserved
 * for real two-way human support conversations (see support_chat.php).
 */
function createNotification($conn, $user, $msg, $url = '', $buttonLabel = '') {
    require_once __DIR__ . '/NotificationService.php';

    // $buttonLabel is kept in the signature for backward compatibility with
    // existing call sites but is no longer used — NotificationService infers
    // a suitable title/icon from $url.
    NotificationService::notifyUser($conn, $user, [
        'message' => $msg,
        'url' => $url,
    ]);
}

/**
 * ส่งอีเมลแจ้งเตือน
 */
function sendOrderEmailNotification($conn, $username, $subject, $body) {
    $stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user || empty($user['email'])) return false;

    // บันทึกลง Log
    $status = 'PENDING';
    $stmt = $conn->prepare("INSERT INTO email_event_logs (user_name, recipient_email, subject, status) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $user['email'], $subject, $status);
    $stmt->execute();
    
    $emailService = new EmailService();
    return $emailService->send($user['email'], $subject, $body);
}

/**
 * อัปเดตสถานะออเดอร์
 */
function updateOrderStatus($conn, $order_id, $current_status, $new_status) {
    $allowed_transitions = [
        OrderStateMachine::PENDING_PAYMENT => [OrderStateMachine::PAID, OrderStateMachine::WAITING_VERIFY, OrderStateMachine::CANCELLED],
        OrderStateMachine::WAITING_VERIFY  => [OrderStateMachine::PAID, OrderStateMachine::CANCELLED],
        OrderStateMachine::PAID            => [OrderStateMachine::SHIPPED, OrderStateMachine::CANCELLED],
        OrderStateMachine::SHIPPED         => [OrderStateMachine::COMPLETED, OrderStateMachine::DISPUTE],
    ];

    if (!in_array($new_status, $allowed_transitions[$current_status] ?? [])) {
        return false;
    }

    $sql = "UPDATE orders SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $new_status, $order_id);
    
    return $stmt->execute();
}

/**
 * สร้าง System Message ในแชท
 */
function sendSystemMessage($conn, $sender, $receiver, $message) {
    $sql = "INSERT INTO messages (sender_name, receiver_name, message) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $sender, $receiver, $message);
    $stmt->execute();
}

/**
 * อัปโหลดไฟล์อย่างปลอดภัย
 */
/**
 * MIME type => extension for the file kinds the upload helpers accept. The
 * extension always comes from the sniffed type, never from the client-supplied
 * filename, so a stored name can never misrepresent its contents.
 */
const FLIXO_UPLOAD_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

/** Dispute evidence additionally accepts screenshots and documents. */
const FLIXO_UPLOAD_EVIDENCE_TYPES = FLIXO_UPLOAD_IMAGE_TYPES + [
    'image/gif' => 'gif',
    'application/pdf' => 'pdf',
];

/**
 * @param array<string, string>|null $allowedTypes MIME => extension; defaults to images
 */
function secureUpload($fileArray, $targetDir, $maxSize = 5242880, ?array $allowedTypes = null) {
    if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('เกิดข้อผิดพลาดในการอัปโหลดไฟล์');
    }
    if ($fileArray['size'] > $maxSize) {
        throw new Exception('ขนาดไฟล์ใหญ่เกินไป');
    }
    if (empty($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        throw new Exception('ไฟล์อัปโหลดไม่ถูกต้อง');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileArray['tmp_name']);
    $allowed = $allowedTypes ?? FLIXO_UPLOAD_IMAGE_TYPES;

    if (!array_key_exists($mime, $allowed)) {
        throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
    }

    $newFilename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        throw new Exception('ไม่สามารถสร้างโฟลเดอร์เก็บไฟล์ได้');
    }
    if (!is_writable($targetDir)) {
        throw new Exception('โฟลเดอร์เก็บไฟล์ไม่มีสิทธิ์เขียน');
    }

    if (!move_uploaded_file($fileArray['tmp_name'], $targetDir . $newFilename)) {
        throw new Exception('ไม่สามารถบันทึกไฟล์ได้');
    }

    return $newFilename;
}

/**
 * อัปโหลดรูปแบบปลอดภัย → Cloudinary สำหรับรูปภาพสาธารณะเมื่อมีการตั้งค่า
 * ไฟล์ส่วนตัว (สลิป/หลักฐาน) ยังใช้ private object storage หรือ local ตามเดิม
 *
 * ค่าที่คืน:
 *   - cloud พร้อมใช้งาน → คืน URL เต็ม (https://...) สำหรับบันทึกลง DB โดยตรง
 *   - fallback local     → คืน "ชื่อไฟล์" เหมือน secureUpload() เดิม
 * ทั้งสองแบบใช้กับ chatImageUrl() / Product::imageUrl() ได้ (รองรับทั้ง URL และชื่อไฟล์)
 *
 * @param array  $fileArray      อาเรย์จาก $_FILES[...]
 * @param string $subdir         โฟลเดอร์/prefix บน object storage เช่น "chat", "products", "slips"
 * @param string $localTargetDir โฟลเดอร์ปลายทางกรณี fallback local (มี/ไม่มี / ต่อท้ายก็ได้)
 * @param int    $maxSize        ขนาดไฟล์สูงสุด (ไบต์)
 * @param bool   $public         true = คืน public URL (รูปสาธารณะ เช่น products/chat)
 *                               false = คืน object key เช่น "slips/xxx.jpg" (ไฟล์ส่วนตัว
 *                                        ที่ต้องสตรีมผ่าน authorized endpoint — ไม่เปิด public URL)
 * @return string public URL หรือ object key (cloud) — หรือ ชื่อไฟล์ (local fallback)
 * @throws Exception เมื่อ validation ไม่ผ่าน หรืออัปโหลดไม่สำเร็จ
 */
function secureUploadCloud(array $fileArray, string $subdir, string $localTargetDir, int $maxSize = 5242880, bool $public = true, ?array $allowedTypes = null): string
{
    // 1) Validation — ตรรกะเดียวกับ secureUpload() เพื่อพฤติกรรมที่สอดคล้องกัน
    if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('เกิดข้อผิดพลาดในการอัปโหลดไฟล์');
    }
    if (($fileArray['size'] ?? 0) > $maxSize) {
        throw new Exception('ขนาดไฟล์ใหญ่เกินไป');
    }
    if (empty($fileArray['tmp_name']) || !is_uploaded_file($fileArray['tmp_name'])) {
        throw new Exception('ไฟล์อัปโหลดไม่ถูกต้อง');
    }

    // ตรวจ MIME จากเนื้อไฟล์จริง ไม่เชื่อ extension/Content-Type ที่ client ส่งมา
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($fileArray['tmp_name']);
    $allowed = $allowedTypes ?? FLIXO_UPLOAD_IMAGE_TYPES;
    if (!array_key_exists($mime, $allowed)) {
        throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
    }

    $filename  = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $subdir    = trim(str_replace(['\\', '..'], ['/', ''], $subdir), '/');
    $objectKey = ($subdir !== '' ? $subdir . '/' : '') . $filename;

    // 2) รูปภาพสาธารณะให้เก็บบน Cloudinary (เช่น รูปสินค้าและแชท)
    if ($public && str_starts_with($mime, 'image/')) {
        require_once __DIR__ . '/CloudinaryStorage.php';
        $cloudinary = new CloudinaryStorage();
        if ($cloudinary->isConfigured()) {
            return $cloudinary->upload($fileArray['tmp_name'], $subdir, pathinfo($filename, PATHINFO_FILENAME), $mime);
        }
        // In production, do not silently save public images on ephemeral server disk.
        if (defined('APP_ENV') && APP_ENV === 'production') {
            throw new Exception('ยังไม่ได้ตั้งค่า Cloudinary สำหรับอัปโหลดรูปภาพ');
        }
    }

    // 3) Private files retain the existing object-storage path.
    require_once __DIR__ . '/ObjectStorage.php';
    $storage = new ObjectStorage();
    if ($storage->isConfigured()) {
        $url = $storage->put($fileArray['tmp_name'], $objectKey, $mime);
        // ไฟล์ส่วนตัว (slips) → เก็บ object key เพื่อสตรีมผ่าน endpoint ที่ตรวจสิทธิ์
        // ไฟล์สาธารณะ (products/chat) → เก็บ public URL ใช้แสดงได้ตรงๆ
        return $public ? $url : $objectKey;
    }

    // 4) Local development fallback (คืนชื่อไฟล์)
    $dir = rtrim($localTargetDir, "/\\") . DIRECTORY_SEPARATOR;
    return secureUpload($fileArray, $dir, $maxSize, $allowed);
}

/**
 * อัปโหลดหลายไฟล์ขึ้น cloud (หรือ fallback local) — คืน array ของ URL/ชื่อไฟล์
 * เวอร์ชัน cloud ของ secureUploadMany()
 *
 * @param array<int,array> $fileArrays
 * @return array<int,string>
 */
function secureUploadCloudMany(array $fileArrays, string $subdir, string $localTargetDir, int $maxSize = 5242880, bool $public = true): array
{
    $saved = [];
    foreach ($fileArrays as $file) {
        $saved[] = secureUploadCloud($file, $subdir, $localTargetDir, $maxSize, $public);
    }
    return $saved;
}

/**
 * แปลงค่าที่เก็บใน DB เป็น URL สำหรับแสดงผล — ใช้ร่วมกันได้ทั้งระบบ
 * (chat, products และรูปสาธารณะอื่นๆ)
 * รองรับ: (1) URL เต็มจาก R2/S3 → คืนตรงๆ  (2) ชื่อไฟล์ local → ต่อ base URL
 *
 * @param string      $ref           ค่าใน DB (URL หรือ ชื่อไฟล์)
 * @param string|null $localBaseUrl  base URL สำหรับไฟล์ local (ดีฟอลต์ = UPLOADS_URL)
 */
function resolveImageUrl(string $ref, ?string $localBaseUrl = null): string
{
    $ref = trim($ref);
    if ($ref === '') {
        return '';
    }
    if (str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://')) {
        return $ref; // cloud object storage URL
    }
    $base = $localBaseUrl !== null
        ? rtrim($localBaseUrl, '/')
        : (defined('UPLOADS_URL')
            ? rtrim(UPLOADS_URL, '/')
            : (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/uploads' : 'uploads'));
    return $base . '/' . rawurlencode(basename($ref));
}

/**
 * @deprecated ใช้ resolveImageUrl() แทน — คงไว้เพื่อ backward compatibility
 */
function chatImageUrl(string $ref): string
{
    return resolveImageUrl($ref);
}

/**
 * แปลง $_FILES[key] เป็น array ของไฟล์ (รองรับ single และ multiple)
 */
function normalizeFileUploads($fieldName) {
    if (!isset($_FILES[$fieldName])) {
        return [];
    }

    $field = $_FILES[$fieldName];
    if (!is_array($field['name'])) {
        if (($field['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$field];
    }

    $files = [];
    $count = count($field['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => $field['name'][$i],
            'type' => $field['type'][$i],
            'tmp_name' => $field['tmp_name'][$i],
            'error' => $field['error'][$i],
            'size' => $field['size'][$i],
        ];
    }

    return $files;
}

/**
 * อัปโหลดหลายไฟล์ — คืนค่า array ของชื่อไฟล์
 */
function secureUploadMany(array $fileArrays, $targetDir, $maxSize = 5242880) {
    $saved = [];
    foreach ($fileArrays as $file) {
        $saved[] = secureUpload($file, $targetDir, $maxSize);
    }
    return $saved;
}

/**
 * ลบไฟล์รูปสินค้าจากดิสก์ (ไม่ throw ถ้าไม่มีไฟล์)
 */
function deleteProductImageFile($filename) {
    if (empty($filename) || str_starts_with($filename, 'http')) {
        return;
    }
    $name = basename(str_replace(['\\', "\0"], '', (string) $filename));
    if ($name === '') {
        return;
    }
    $candidates = [
        flixoUploadsSubdir('products') . $name,
        __DIR__ . '/../uploads/products/' . $name,
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
?>
