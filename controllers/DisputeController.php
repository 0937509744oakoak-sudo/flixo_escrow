<?php
// controllers/DisputeController.php
require_once __DIR__ . '/../includes/init.php';
require __DIR__ . '/admin_check_proxy.php';
require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/WalletService.php';
require_once __DIR__ . '/../includes/Rbac.php';
require_once __DIR__ . '/../includes/DisputeFlowService.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Dispute.php';

verifyCsrfToken($_POST['csrf_token'] ?? '');

header('Content-Type: application/json; charset=utf-8');

try {
    Rbac::requirePermission($conn, $_SESSION['user'], 'dispute.resolve');

    $action = $_POST['action'] ?? '';
    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) {
        throw new Exception('Invalid order ID');
    }

    $allowed = ['refund_buyer', 'payout_seller', 'split_resolution'];
    if (!in_array($action, $allowed, true)) {
        throw new Exception('Action ไม่ถูกต้อง');
    }

    $orderModel = new Order($conn);
    $disputeModel = new Dispute($conn);
    $walletService = new WalletService($conn);
    $order = $orderModel->findById($order_id);
    if (!$order) {
        throw new Exception('ไม่พบรายการคำสั่งซื้อ');
    }
    if ($order['status'] !== OrderStateMachine::DISPUTE) {
        throw new Exception('ออเดอร์ไม่อยู่ในสถานะข้อพิพาท');
    }

    $dispute = $disputeModel->findByOrderId($order_id);
    if (!$dispute) {
        throw new Exception('ไม่พบข้อพิพาท');
    }

    $flow = new DisputeFlowService($conn);
    $dispute = $flow->syncAndGet((int)$dispute['id']);
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $judgeCheck = $flow->canAdminJudge($dispute, $force);
    if (!$judgeCheck['ok']) {
        throw new Exception($judgeCheck['reason']);
    }

    $adminUser = $_SESSION['user'];
    $note = trim($_POST['admin_note'] ?? '');

    if ($action === 'refund_buyer') {
        if (!$orderModel->refundToBuyer($order_id)) {
            throw new Exception('คืนเงินไม่สำเร็จ');
        }
        $disputeModel->resolve((int)$dispute['id'], 'REFUND_BUYER', $adminUser, (float)$order['amount'], 0, $note);
        createNotification($conn, $order['buyer_name'], "✅ แอดมินตัดสินคืนเงินให้คุณสำหรับออเดอร์ #$order_id", "dashboard.php?tab=orders");
        createNotification($conn, $order['seller_name'], "❌ แอดมินตัดสินคืนเงินให้ผู้ซื้อ (ออเดอร์ #$order_id)", "dashboard.php?tab=orders");
        echo json_encode(['status' => 'success', 'message' => 'คืนเงินให้ผู้ซื้อเรียบร้อย']);
        exit;
    }

    if ($action === 'payout_seller') {
        if (!$orderModel->forceComplete($order_id)) {
            throw new Exception('โอนเงินไม่สำเร็จ');
        }
        $disputeModel->resolve((int)$dispute['id'], 'PAYOUT_SELLER', $adminUser, 0, (float)$order['amount'], $note);
        createNotification($conn, $order['seller_name'], "💰 แอดมินตัดสินโอนเงินให้คุณสำหรับออเดอร์ #$order_id", "earnings.php");
        createNotification($conn, $order['buyer_name'], "ℹ️ แอดมินตัดสินโอนเงินให้ผู้ขาย (ออเดอร์ #$order_id)", "dashboard.php?tab=orders");
        echo json_encode(['status' => 'success', 'message' => 'โอนเงินให้ผู้ขายเรียบร้อย']);
        exit;
    }

    if ($action === 'split_resolution') {
        $buyerAmt = (float)($_POST['buyer_amount'] ?? 0);
        $sellerAmt = (float)($_POST['seller_amount'] ?? 0);
        $total = (float)$order['amount'];
        if ($buyerAmt <= 0 || $sellerAmt <= 0 || abs(($buyerAmt + $sellerAmt) - $total) > 0.01) {
            throw new Exception("ยอดแบ่งต้องรวมเท่ากับ ฿" . number_format($total, 2));
        }
        if (!$walletService->splitDisputeResolution($order['buyer_name'], $order['seller_name'], $order_id, $buyerAmt, $sellerAmt, $adminUser)) {
            throw new Exception('แบ่งเงินไม่สำเร็จ');
        }
        $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::COMPLETED, $adminUser, "แอดมินแบ่งเงิน: ผู้ซื้อ ฿{$buyerAmt} / ผู้ขาย ฿{$sellerAmt}", true);
        $disputeModel->resolve((int)$dispute['id'], 'SPLIT', $adminUser, $buyerAmt, $sellerAmt, $note);
        createNotification($conn, $order['buyer_name'], "⚖️ แอดมินแบ่งเงินคืนคุณ ฿" . number_format($buyerAmt, 2) . " (ออเดอร์ #$order_id)", "dashboard.php?tab=orders");
        createNotification($conn, $order['seller_name'], "⚖️ แอดมินแบ่งเงินให้คุณ ฿" . number_format($sellerAmt, 2) . " (ออเดอร์ #$order_id)", "earnings.php");
        echo json_encode(['status' => 'success', 'message' => 'แบ่งเงินตามที่ตัดสินเรียบร้อย']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
