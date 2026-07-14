<?php
// includes/functions.php

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectTo($url) {
    header('Location: ' . $url);
    exit();
}

function getPostValue($key, $default = '') {
    return trim($_POST[$key] ?? $default);
}

function isPostRequest() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function sendLineNotify($message) {
    $token = "YOUR_LINE_TOKEN";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://notify-api.line.me/api/notify");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "message=$message");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    $headers = array('Content-type: application/x-www-form-urlencoded', 'Authorization: Bearer '.$token,);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function userDisplayName(mysqli $conn, string $username): string {
    static $cache = [];
    if (isset($cache[$username])) {
        return $cache[$username];
    }
    require_once __DIR__ . '/../models/User.php';
    $cache[$username] = (new User($conn))->getDisplayName($username);
    return $cache[$username];
}

function sanitizeUsername($username) {
    $username = trim((string)$username);
    $username = preg_replace('/[^A-Za-z0-9_.-]/u', '', $username);
    return mb_substr($username, 0, 32);
}

function sanitizePhone($phone) {
    return preg_replace('/[^0-9]/', '', (string)$phone);
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/** Normalize Thai/Latin person names for comparison (KYC vs bank account). */
function normalizePersonName(string $name): string {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    return mb_strtolower($name, 'UTF-8');
}

function bankAccountNameMatchesKyc(string $accountName, string $kycName): bool {
    $accountName = normalizePersonName($accountName);
    $kycName = normalizePersonName($kycName);
    if ($accountName === '' || $kycName === '') {
        return false;
    }
    return $accountName === $kycName;
}

function clearSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// ========================================
// EMAIL NOTIFICATION FUNCTIONS
// ========================================

/**
 * Send email notification to a user
 * @param mysqli $conn Database connection
 * @param string $username Username to send email to
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @return bool
 */
function sendEmailNotification($conn, $username, $subject, $body) {
    require_once __DIR__ . '/EmailService.php';
    
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        return false;
    }
    
    // Get user's email address
    $sql = "SELECT email FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user || empty($user['email'])) {
        return false;
    }
    
    $emailService = new EmailService();
    return $emailService->send($user['email'], $subject, $body, true);
}

/**
 * Send order status notification
 * Triggered when order status changes (especially to PAID)
 * 
 * @param mysqli $conn Database connection
 * @param int $orderId Order ID
 * @param string $newStatus New order status
 * @param string $oldStatus Previous order status
 * @return bool
 */
function notifyOrderStatusChange($conn, $orderId, $newStatus, $oldStatus) {
    
    // Get order details
    $sql = "SELECT * FROM orders WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        return false;
    }
    
    // Get status label
    $statusLabel = getOrderStatusLabel($newStatus);
    
    // Build email subject and body based on status change
    $emailsSent = true;
    
    switch ($newStatus) {
        case 'PAID':
            // Notify both buyer and seller
            $subject = "✓ ยืนยันการชำระเงินแล้ว - ออเดอร์ #" . $order['id'];
            $body = buildOrderEmailTemplate(
                $order,
                'PAID',
                "การชำระเงินของคุณได้รับการยืนยันแล้ว!",
                "ผู้ขายจะเริ่มจัดเตรียมของสำหรับการจัดส่ง"
            );
            $emailsSent = sendEmailNotification($conn, $order['buyer_name'], $subject, $body);
            
            // Notify seller
            $subject = "📦 ได้รับการชำระเงินแล้ว - โปรดจัดส่งของ (ออเดอร์ #" . $order['id'] . ")";
            $body = buildOrderEmailTemplate(
                $order,
                'PAID',
                "ผู้ซื้อได้ชำระเงินแล้ว!",
                "โปรดจัดเตรียมและจัดส่งของตามรายละเอียดในระบบ"
            );
            $emailsSent = $emailsSent && sendEmailNotification($conn, $order['seller_name'], $subject, $body);
            break;
            
        case 'SHIPPED':
            // Notify buyer about shipment
            $subject = "🚚 สินค้ากำลังจัดส่ง - ออเดอร์ #" . $order['id'];
            $body = buildOrderEmailTemplate(
                $order,
                'SHIPPED',
                "สินค้าของคุณกำลังจัดส่ง!",
                "เลขติดตามพัสดุ: " . ($order['tracking_number'] ?? 'N/A') . "\n" .
                "บริษัทขนส่ง: " . ($order['courier'] ?? 'N/A')
            );
            $emailsSent = sendEmailNotification($conn, $order['buyer_name'], $subject, $body);
            break;
            
        case 'COMPLETED':
            // Notify both parties
            $subject = "✅ ออเดอร์สำเร็จ - ขอบคุณที่ใช้บริการ (ออเดอร์ #" . $order['id'] . ")";
            $body = buildOrderEmailTemplate(
                $order,
                'COMPLETED',
                "ธุรกรรมสำเร็จ!",
                "ขอบคุณที่ใช้บริการของเรา! โปรดพิจารณาให้คะแนนและรีวิวผู้ขาย"
            );
            $emailsSent = sendEmailNotification($conn, $order['buyer_name'], $subject, $body);
            
            // Notify seller
            $subject = "✅ ออเดอร์สำเร็จ - ขอบคุณที่ทำการขายกับเรา (ออเดอร์ #" . $order['id'] . ")";
            $body = buildOrderEmailTemplate(
                $order,
                'COMPLETED',
                "ธุรกรรมสำเร็จ!",
                "เงินจะถูกโอนไปยังบัญชีของคุณในระบบในไม่ช้า"
            );
            $emailsSent = $emailsSent && sendEmailNotification($conn, $order['seller_name'], $subject, $body);
            break;
            
        case 'CANCELLED':
            // Notify both parties
            $subject = "❌ ออเดอร์ถูกยกเลิก (ออเดอร์ #" . $order['id'] . ")";
            $body = buildOrderEmailTemplate(
                $order,
                'CANCELLED',
                "ออเดอร์ถูกยกเลิก",
                "ออเดอร์นี้ได้รับการยกเลิก ถ้ามีการชำระเงิน เงินจะถูกคืนตามนโยบาย"
            );
            $emailsSent = sendEmailNotification($conn, $order['buyer_name'], $subject, $body);
            $emailsSent = $emailsSent && sendEmailNotification($conn, $order['seller_name'], $subject, $body);
            break;
            
        case 'DISPUTE':
            // Notify admin about dispute
            $subject = "⚠️ มีการเปิดข้อพิพาท - โปรดตรวจสอบ (ออเดอร์ #" . $order['id'] . ")";
            $body = buildOrderEmailTemplate(
                $order,
                'DISPUTE',
                "มีการเปิดข้อพิพาทในระบบ",
                "โปรดเข้าสู่ระบบ FLIXO และตรวจสอบรายละเอียดข้อพิพาท"
            );
            break;
    }
    
    return $emailsSent;
}

/**
 * Get order status label in Thai
 */
function getOrderStatusLabel($status) {
    $labels = [
        'CREATED'         => 'รอรับข้อเสนอ',
        'PENDING_PAYMENT' => 'รอชำระเงิน',
        'WAITING_VERIFY'  => 'รอตรวจสอบสลิป',
        'PAID'            => 'รอจัดส่ง',
        'SHIPPED'         => 'รอผู้ซื้อรับของ',
        'COMPLETED'       => 'สำเร็จ (โอนเงินแล้ว)',
        'CANCELLED'       => 'ยกเลิก',
        'DISPUTE'         => 'มีข้อพิพาท',
    ];
    return $labels[$status] ?? $status;
}

/**
 * Build HTML email template for order notifications
 */
function buildOrderEmailTemplate($order, $status, $mainMessage, $additionalInfo) {
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/flixo_escrow';
    $systemName = defined('SYSTEM_NAME') ? SYSTEM_NAME : 'FLIXO Escrow';
    $statusLabel = getOrderStatusLabel($status);
    
    $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: white; padding: 30px; border-radius: 0 0 8px 8px; }
        .order-detail { background: #f9f9f9; padding: 15px; margin: 15px 0; border-left: 4px solid #667eea; }
        .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .detail-row:last-child { border-bottom: none; }
        .label { font-weight: bold; color: #666; }
        .value { color: #333; text-align: right; }
        .status-badge { display: inline-block; padding: 8px 12px; border-radius: 4px; font-weight: bold; margin: 10px 0; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-shipped { background: #d1ecf1; color: #0c5460; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { background: #f5f5f5; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #ddd; }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$systemName}</h1>
            <p>ข้อมูลอัปเดตสถานะออเดอร์</p>
        </div>
        
        <div class="content">
            <h2>{$mainMessage}</h2>
            
            <div class="status-badge status-{$status}">
                สถานะ: {$statusLabel}
            </div>
            
            <div class="order-detail">
                <div class="detail-row">
                    <span class="label">เลขออเดอร์:</span>
                    <span class="value">#{$order['id']}</span>
                </div>
                <div class="detail-row">
                    <span class="label">สินค้า:</span>
                    <span class="value">{$order['item_name']}</span>
                </div>
                <div class="detail-row">
                    <span class="label">จำนวนเงิน:</span>
                    <span class="value">฿{$order['amount']}</span>
                </div>
                <div class="detail-row">
                    <span class="label">ผู้ซื้อ:</span>
                    <span class="value">{$order['buyer_name']}</span>
                </div>
                <div class="detail-row">
                    <span class="label">ผู้ขาย:</span>
                    <span class="value">{$order['seller_name']}</span>
                </div>
                <div class="detail-row">
                    <span class="label">วันที่สร้างออเดอร์:</span>
                    <span class="value">{$order['created_at']}</span>
                </div>
            </div>
            
            <p>{$additionalInfo}</p>
            
            <p style="text-align: center;">
                <a href="{$baseUrl}/dashboard.php?tab=orders" class="button">ดูออเดอร์ของฉัน</a>
            </p>
            
            <p style="color: #999; font-size: 12px;">
                หมายเหตุ: ห้ามตอบสนองต่ออีเมลนี้โดยตรง โปรดใช้ระบบแชท FLIXO สำหรับการสื่อสาร
            </p>
        </div>
        
        <div class="footer">
            <p>© 2026 {$systemName} - ระบบ Escrow อย่างปลอดภัย</p>
            <p>อีเมลนี้ถูกส่งไปยังคุณเพราะคุณมีบัญชี {$systemName}</p>
        </div>
    </div>
</body>
</html>
HTML;
    
    return $html;
}

