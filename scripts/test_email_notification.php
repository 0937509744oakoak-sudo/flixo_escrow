<?php
/**
 * Test Email Notification System
 * 
 * This script tests the email notification system with order status changes
 * 
 * Usage:
 *   php scripts/test_email_notification.php [test_type] [optional_param]
 * 
 * Examples:
 *   php scripts/test_email_notification.php simple [username]
 *   php scripts/test_email_notification.php order [order_id]
 *   php scripts/test_email_notification.php config
 *   php scripts/test_email_notification.php status
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../models/Order.php';

$testType = $argv[1] ?? 'status';
$param = $argv[2] ?? '';

echo "========================================\n";
echo "FLIXO Email Notification Test\n";
echo "========================================\n\n";

switch ($testType) {
    case 'config':
        testConfiguration();
        break;
        
    case 'status':
        showStatus();
        break;
        
    case 'simple':
        testSimpleEmail($param);
        break;
        
    case 'order':
        testOrderNotification($param);
        break;
        
    case 'paid':
        testPaidNotification($param);
        break;
        
    default:
        showUsage();
}

function testConfiguration() {
    echo "1. CHECKING ENVIRONMENT VARIABLES\n";
    echo "   ├─ MAIL_ENABLED: " . (defined('MAIL_ENABLED') && MAIL_ENABLED ? '✓ YES' : '✗ NO') . "\n";
    echo "   ├─ MAIL_FROM_ADDRESS: " . MAIL_FROM_ADDRESS . "\n";
    echo "   ├─ MAIL_FROM_NAME: " . MAIL_FROM_NAME . "\n";
    echo "   └─ MAIL_SMTP_HOST: " . (empty(MAIL_SMTP_HOST) ? '(Using PHP mail())' : MAIL_SMTP_HOST) . "\n\n";
    
    echo "2. CHECKING SMTP CONFIGURATION\n";
    if (!empty(MAIL_SMTP_HOST)) {
        echo "   ├─ SMTP Host: " . MAIL_SMTP_HOST . ":" . MAIL_SMTP_PORT . "\n";
        echo "   ├─ SMTP User: " . MAIL_SMTP_USERNAME . "\n";
        echo "   ├─ SMTP Secure: " . MAIL_SMTP_SECURE . "\n";
        
        // Test SMTP connection
        echo "   └─ Testing SMTP Connection...\n";
        $emailService = new EmailService();
        
        $testResult = @fsockopen(
            (MAIL_SMTP_SECURE === 'ssl' ? 'ssl://' : '') . MAIL_SMTP_HOST,
            MAIL_SMTP_PORT,
            $errno,
            $errstr,
            5
        );
        
        if ($testResult) {
            echo "       ✓ SMTP Server is reachable\n";
            fclose($testResult);
        } else {
            echo "       ✗ Cannot connect to SMTP server: $errstr ($errno)\n";
        }
    } else {
        echo "   └─ Using PHP mail() function (no SMTP configuration)\n";
    }
    
    echo "\n3. DATABASE CONNECTION\n";
    global $conn;
    if ($conn && $conn->ping()) {
        echo "   ✓ Database connection OK\n";
    } else {
        echo "   ✗ Database connection failed\n";
    }
    
    echo "\n";
}

function showStatus() {
    echo "Email Notification System Status:\n\n";
    
    if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
        echo "⚠️  Email notifications are DISABLED\n";
        echo "   To enable, set MAIL_ENABLED=1 in .env file\n\n";
        return;
    }
    
    echo "✓ Email notifications are ENABLED\n\n";
    echo "Configuration:\n";
    echo "  From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\n";
    
    if (!empty(MAIL_SMTP_HOST)) {
        echo "  SMTP: " . MAIL_SMTP_HOST . ":" . MAIL_SMTP_PORT . "\n";
        echo "  Auth: " . MAIL_SMTP_USERNAME . "\n";
    } else {
        echo "  Method: PHP mail()\n";
    }
    
    echo "\nTo send test emails, use:\n";
    echo "  php scripts/test_email_notification.php simple [username]\n";
    echo "  php scripts/test_email_notification.php order [order_id]\n";
    echo "  php scripts/test_email_notification.php paid [order_id]\n";
    echo "\n";
}

function testSimpleEmail($username) {
    if (empty($username)) {
        echo "Usage: php scripts/test_email_notification.php simple [username]\n";
        return;
    }
    
    global $conn;
    
    echo "Sending simple test email to user: $username\n\n";
    
    // Get user email
    $sql = "SELECT email FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        echo "✗ User '$username' not found\n";
        return;
    }
    
    echo "User email: " . $user['email'] . "\n\n";
    
    $subject = "ทดสอบระบบการแจ้งเตือนอีเมล FLIXO";
    $body = buildTestEmailTemplate($username);
    
    $result = sendEmailNotification($conn, $username, $subject, $body);
    
    if ($result) {
        echo "✓ Email sent successfully!\n";
        echo "\nYou should receive the email at: " . $user['email'] . "\n";
        echo "Please check your inbox (or spam folder).\n";
    } else {
        echo "✗ Failed to send email\n";
        echo "Check the configuration settings above.\n";
    }
    
    echo "\n";
}

function testOrderNotification($orderId) {
    if (empty($orderId)) {
        echo "Usage: php scripts/test_email_notification.php order [order_id]\n";
        return;
    }
    
    global $conn;
    
    echo "Testing order notification for order ID: $orderId\n\n";
    
    // Get order details
    $orderModel = new Order($conn);
    $order = $orderModel->findById((int)$orderId);
    
    if (!$order) {
        echo "✗ Order #$orderId not found\n";
        return;
    }
    
    echo "Order Details:\n";
    echo "  Buyer: " . $order['buyer_name'] . "\n";
    echo "  Seller: " . $order['seller_name'] . "\n";
    echo "  Item: " . $order['item_name'] . "\n";
    echo "  Amount: ฿" . $order['amount'] . "\n";
    echo "  Status: " . $order['status'] . "\n\n";
    
    // Get buyer email
    $sql = "SELECT email FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $order['buyer_name']);
    $stmt->execute();
    $buyerResult = $stmt->get_result()->fetch_assoc();
    
    if ($buyerResult) {
        echo "Sending notification to buyer: " . $buyerResult['email'] . "\n";
    }
    
    // Get seller email
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $order['seller_name']);
    $stmt->execute();
    $sellerResult = $stmt->get_result()->fetch_assoc();
    
    if ($sellerResult) {
        echo "Sending notification to seller: " . $sellerResult['email'] . "\n";
    }
    
    echo "\nTriggering notification...\n";
    
    // Simulate notification
    $result = notifyOrderStatusChange($conn, (int)$orderId, $order['status'], '');
    
    if ($result) {
        echo "✓ Order notification sent successfully!\n";
    } else {
        echo "✗ Failed to send order notification\n";
    }
    
    echo "\n";
}

function testPaidNotification($orderId) {
    if (empty($orderId)) {
        echo "Usage: php scripts/test_email_notification.php paid [order_id]\n";
        echo "\nThis simulates what happens when an order status changes to PAID\n";
        return;
    }
    
    global $conn;
    
    echo "Simulating PAID status notification for order ID: $orderId\n\n";
    
    // Get order details
    $orderModel = new Order($conn);
    $order = $orderModel->findById((int)$orderId);
    
    if (!$order) {
        echo "✗ Order #$orderId not found\n";
        return;
    }
    
    echo "Order Details:\n";
    echo "  Buyer: " . $order['buyer_name'] . "\n";
    echo "  Seller: " . $order['seller_name'] . "\n";
    echo "  Item: " . $order['item_name'] . "\n";
    echo "  Amount: ฿" . $order['amount'] . "\n";
    echo "  Current Status: " . $order['status'] . "\n\n";
    
    echo "Simulating status change: " . $order['status'] . " → PAID\n";
    echo "Sending notifications...\n\n";
    
    // Simulate the notification that would be sent when status changes to PAID
    $result = notifyOrderStatusChange($conn, (int)$orderId, 'PAID', $order['status']);
    
    if ($result) {
        echo "✓ Notifications sent successfully!\n";
        echo "\nBoth buyer and seller should receive emails about the payment confirmation.\n";
    } else {
        echo "✗ Failed to send notifications\n";
    }
    
    echo "\n";
}

function buildTestEmailTemplate($username) {
    $systemName = defined('SYSTEM_NAME') ? SYSTEM_NAME : 'FLIXO Escrow';
    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/flixo_escrow';
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: white; padding: 30px; border-radius: 0 0 8px 8px; }
        .status-badge { display: inline-block; padding: 8px 12px; background: #d4edda; color: #155724; border-radius: 4px; font-weight: bold; margin: 10px 0; }
        .footer { background: #f5f5f5; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$systemName}</h1>
            <p>ทดสอบระบบการแจ้งเตือน</p>
        </div>
        
        <div class="content">
            <h2>✓ ระบบการแจ้งเตือนอีเมลทำงานได้!</h2>
            
            <p>สวัสดี <strong>{$username}</strong>,</p>
            
            <p>นี่เป็นอีเมลทดสอบจากระบบ {$systemName} เพื่อตรวจสอบว่าการแจ้งเตือนอีเมลทำงานได้ตามปกติ</p>
            
            <div class="status-badge">ระบบอีเมล: ✓ ใช้งานได้</div>
            
            <p>หากคุณได้รับอีเมลนี้ แปลว่า:</p>
            <ul>
                <li>✓ บัญชีผู้ใช้ของคุณเชื่อมต่อกับแอดเดรสอีเมลนี้แล้ว</li>
                <li>✓ ระบบ SMTP กำลังทำงาน</li>
                <li>✓ คุณจะได้รับการแจ้งเตือนเมื่อมีการเปลี่ยนแปลงสถานะออเดอร์</li>
            </ul>
            
            <p style="text-align: center;">
                <a href="{$baseUrl}/dashboard.php?tab=orders" style="display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 15px 0;">ดูออเดอร์ของฉัน</a>
            </p>
            
            <p style="color: #999; font-size: 12px;">
                หากคุณไม่ได้ขอให้ส่งอีเมลนี้ โปรดติดต่อสอบถามกับทีมสนับสนุน
            </p>
        </div>
        
        <div class="footer">
            <p>© 2026 {$systemName} - ระบบ Escrow อย่างปลอดภัย</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function showUsage() {
    echo "Usage: php scripts/test_email_notification.php [test_type] [optional_param]\n\n";
    echo "Test Types:\n";
    echo "  config       - Show SMTP configuration and test connection\n";
    echo "  status       - Show current email notification status\n";
    echo "  simple       - Send a simple test email\n";
    echo "                 Usage: php scripts/test_email_notification.php simple [username]\n";
    echo "  order        - Test order notification\n";
    echo "                 Usage: php scripts/test_email_notification.php order [order_id]\n";
    echo "  paid         - Simulate PAID status notification\n";
    echo "                 Usage: php scripts/test_email_notification.php paid [order_id]\n\n";
    echo "Examples:\n";
    echo "  php scripts/test_email_notification.php config\n";
    echo "  php scripts/test_email_notification.php simple buyer1\n";
    echo "  php scripts/test_email_notification.php paid 5\n\n";
}
?>
