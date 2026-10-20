<?php
// includes/omise_helper.php

require_once __DIR__ . '/OrderStateMachine.php';
require_once __DIR__ . '/order_helper.php';
require_once __DIR__ . '/OmiseService.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/OmisePayment.php';

function markOrderPaidFromOmise(mysqli $conn, int $orderId, array $chargeData, string $paymentType = 'card'): bool {
    $orderModel = new Order($conn);
    $order = $orderModel->findById($orderId);
    if (!$order) {
        return false;
    }
    if ($order['status'] === OrderStateMachine::PAID) {
        return true;
    }
    if (!in_array($order['status'], [OrderStateMachine::PENDING_PAYMENT, OrderStateMachine::WAITING_VERIFY], true)) {
        return false;
    }

    $chargeId = $chargeData['id'] ?? '';
    $amount = (float)($chargeData['amount'] ?? 0) / 100;
    $status = $chargeData['status'] ?? 'unknown';
    $paid = !empty($chargeData['paid']) && $status === 'successful';

    $payModel = new OmisePayment($conn);
    $payModel->record($orderId, $chargeId, $paymentType, $amount, (int)($chargeData['amount'] ?? 0), $status, $chargeData);

    if (!$paid) {
        return false;
    }

    // A charge can legitimately carry an order_id while being for the wrong
    // amount (retry with a stale source, tampered client). Escrow must never be
    // funded for less than the order is worth.
    $expectedSatang = OmiseService::toSatang((float) $order['amount']);
    $paidSatang = (int) ($chargeData['amount'] ?? 0);
    if ($paidSatang < $expectedSatang) {
        ErrorLogger::write('error', 'Omise charge underpays order — not marking as paid', [
            'order_id' => $orderId,
            'charge_id' => $chargeId,
            'paid_satang' => $paidSatang,
            'expected_satang' => $expectedSatang,
        ]);
        return false;
    }

    $systemAdmin = ['username' => 'Omise', 'role' => 'admin'];
    $oldStatus = $order['status'];
    if ($oldStatus === OrderStateMachine::PENDING_PAYMENT) {
        OrderStateMachine::validate($order, OrderStateMachine::PAID, $systemAdmin);
        $orderModel->changeStatus($orderId, $oldStatus, OrderStateMachine::PAID, 'Omise', 'ชำระเงินผ่าน Omise (' . $paymentType . ')');
    } elseif ($oldStatus === OrderStateMachine::WAITING_VERIFY) {
        OrderStateMachine::validate($order, OrderStateMachine::PAID, $systemAdmin);
        $orderModel->changeStatus($orderId, $oldStatus, OrderStateMachine::PAID, 'Omise', 'ยืนยันชำระ Omise');
    }

    $meta = json_encode(['via' => 'omise', 'charge_id' => $chargeId, 'type' => $paymentType]);
    $tx = $conn->prepare("INSERT INTO transaction_logs (order_id, type, amount, currency, meta) VALUES (?, 'omise_paid', ?, 'THB', ?)");
    $tx->bind_param('ids', $orderId, $amount, $meta);
    $tx->execute();

    createNotification($conn, $order['seller_name'], "💰 ออเดอร์ #{$orderId} ชำระเงินผ่าน Omise แล้ว กรุณาจัดส่งสินค้า", "dashboard.php?tab=orders");
    createNotification($conn, $order['buyer_name'], "✅ ชำระเงินออเดอร์ #{$orderId} สำเร็จ — เงินพักใน Escrow อย่างปลอดภัย รอผู้ขายจัดส่ง", "buy_product.php?order_id={$orderId}");

    return true;
}

function processOmisePromptPaySource(mysqli $conn, int $orderId, string $sourceId): array {
    $omise = new OmiseService();
    $source = $omise->retrieveSource($sourceId);
    $payModel = new OmisePayment($conn);
    $orderModel = new Order($conn);
    $order = $orderModel->findById($orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'ไม่พบออเดอร์'];
    }

    $amountSatang = OmiseService::toSatang((float)$order['amount']);
    $status = $source['status'] ?? 'pending';
    $payModel->record($orderId, $sourceId, 'promptpay', (float)$order['amount'], $amountSatang, $status, $source);

    if ($omise->isSourceChargeable($source)) {
        $charge = $omise->createChargeFromSource(
            $sourceId,
            $amountSatang,
            'FLIXO Order #' . $orderId,
            ['order_id' => (string)$orderId]
        );
        if ($omise->isPaid($charge)) {
            markOrderPaidFromOmise($conn, $orderId, $charge, 'promptpay');
            return ['ok' => true, 'paid' => true, 'charge' => $charge];
        }
        return ['ok' => true, 'paid' => false, 'status' => $charge['status'] ?? 'pending'];
    }

    $qrUrl = $source['scannable_code']['image']['download_uri'] ?? null;
    return ['ok' => true, 'paid' => false, 'status' => $status, 'qr_url' => $qrUrl, 'source' => $source];
}
