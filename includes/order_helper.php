<?php
// includes/order_helper.php

require_once 'db.php';
require_once 'OrderStateMachine.php';
require_once 'EmailService.php';

/**
 * EasySlip returns amount as either a number or { amount: number, local: {...} }.
 * Casting an array to float in PHP yields 1.0 — never do (float)$data['amount'].
 */
function extractSlipAmount(array $slipData): float {
    $raw = $slipData['amount'] ?? null;

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
        return [
            'status' => 'success',
            'message' => 'ระบบได้รับสลิปแล้ว กำลังตรวจสอบอยู่',
            'already_submitted' => true,
        ];
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
function secureUpload($fileArray, $targetDir, $maxSize = 5242880) {
    if (!isset($fileArray['error']) || $fileArray['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('เกิดข้อผิดพลาดในการอัปโหลดไฟล์');
    }
    if ($fileArray['size'] > $maxSize) {
        throw new Exception('ขนาดไฟล์ใหญ่เกินไป');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileArray['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

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
    $path = __DIR__ . '/../uploads/products/' . ltrim($filename, '/');
    if (is_file($path)) {
        @unlink($path);
    }
}
?>
