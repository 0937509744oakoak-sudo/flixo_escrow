<?php
// controllers/DisputeUserController.php — seller response, settlement (buyer/seller)
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/DisputeFlowService.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../models/Order.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

try {
    $action = $_POST['action'] ?? '';
    $disputeId = (int)($_POST['dispute_id'] ?? 0);
    $user = $_SESSION['user'];

    if ($disputeId <= 0) {
        throw new Exception('Invalid dispute ID');
    }

    $disputeModel = new Dispute($conn);
    $dispute = $disputeModel->findById($disputeId);
    if (!$dispute) {
        throw new Exception('ไม่พบข้อพิพาท');
    }

    $order = (new Order($conn))->findById((int)$dispute['order_id']);
    if (!$order || ($order['buyer_name'] !== $user && $order['seller_name'] !== $user)) {
        throw new Exception('ไม่มีสิทธิ์');
    }

    $flow = new DisputeFlowService($conn);

    if ($action === 'seller_response') {
        $text = trim($_POST['response'] ?? '');
        $result = $flow->submitSellerResponse($disputeId, $user, $text);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'propose_settlement') {
        $buyerAmt = (float)($_POST['buyer_amount'] ?? 0);
        $sellerAmt = (float)($_POST['seller_amount'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $result = $flow->proposeSettlement($disputeId, $user, $buyerAmt, $sellerAmt, $message);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'respond_settlement') {
        $response = $_POST['response'] ?? '';
        $counterBuyer = isset($_POST['buyer_amount']) ? (float)$_POST['buyer_amount'] : null;
        $counterSeller = isset($_POST['seller_amount']) ? (float)$_POST['seller_amount'] : null;
        $message = trim($_POST['message'] ?? '');
        $result = $flow->respondSettlement($disputeId, $user, $response, $counterBuyer, $counterSeller, $message);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Action ไม่ถูกต้อง');
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
