<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../includes/WalletService.php';
require_once __DIR__ . '/../models/Order.php';

// Webhook endpoint ใช้ token แยกต่างหากแทน CSRF เพราะเป็นการเรียกจากระบบภายนอก
header('Content-Type: application/json; charset=utf-8');
$json_data = file_get_contents('php://input');
$payload = json_decode($json_data, true);

if (!$payload) {
    http_response_code(400);
    exit(json_encode(['error' => 'No payload received']));
}

if (!isset($payload['secret_token']) || $payload['secret_token'] !== WEBHOOK_SECRET) {
    http_response_code(401); // Unauthorized
    error_log("Webhook Error: Invalid Token");
    exit(json_encode(['error' => 'Invalid Secret Token']));
}

if ($payload['status'] === 'success') {
    $order_id = (int)$payload['order_id'];
    $amount_received = (float)$payload['amount'];

    $stmt = $conn->prepare("SELECT id, amount, status, seller_name, buyer_name FROM orders WHERE id = ? AND status = '" . OrderStateMachine::WAITING_VERIFY . "'");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if ($order) {
        if ($amount_received >= $order['amount']) {
            $orderModel = new Order($conn);
            $systemAdmin = ['username' => 'Webhook', 'role' => 'admin'];
            try {
                OrderStateMachine::validate($order, OrderStateMachine::PAID, $systemAdmin);
                if ($orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::PAID, 'Webhook', 'ตรวจสลิปผ่าน Webhook')) {
                $msg = "✅ ระบบตรวจสอบสลิปอัตโนมัติสำเร็จ!\nยอดเงิน ฿" . number_format($amount_received) . " เข้าสู่ Escrow แล้ว\nกรุณาจัดส่งสินค้าและแจ้งเลขพัสดุครับ";
                $chat_stmt = $conn->prepare("INSERT INTO messages (sender_name, receiver_name, message) VALUES ('System', ?, ?)");
                $chat_stmt->bind_param("ss", $order['seller_name'], $msg);
                $chat_stmt->execute();

                require_once __DIR__ . '/../includes/order_helper.php';
                createNotification($conn, $order['seller_name'], "💰 ยอดเงินออเดอร์ #{$order_id} เข้าสู่ Escrow แล้ว กรุณาจัดส่งสินค้าและแจ้งเลขพัสดุ", "dashboard.php?tab=orders");
                createNotification($conn, $order['buyer_name'], "✅ ยืนยันการชำระเงินออเดอร์ #{$order_id} แล้ว — เงินพักใน Escrow อย่างปลอดภัย รอผู้ขายจัดส่ง", "buy_product.php?order_id={$order_id}");

                http_response_code(200);
                echo json_encode(['status' => 'success', 'message' => 'Order automatically verified']);
                exit();
                }
            } catch (Exception $e) {
                error_log('Webhook changeStatus: ' . $e->getMessage());
            }
        } else {
            error_log("Webhook Error: ยอดเงินไม่ตรง (Order ID: $order_id)");
        }
    }
}

http_response_code(400);
echo json_encode(['status' => 'ignored']);
?>