<?php
// controllers/TrackingController.php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/CourierService.php';
require_once __DIR__ . '/../models/Order.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$me = $_SESSION['user'];
$orderId = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid order']);
    exit;
}

$orderModel = new Order($conn);
$order = $orderModel->findById($orderId);
if (!$order || ($order['buyer_name'] !== $me && $order['seller_name'] !== $me && !in_array($_SESSION['role'] ?? '', ['admin', 'owner'], true))) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$courier = new CourierService($conn);

if ($action === 'timeline') {
    $trackingNumber = (string) ($order['tracking_number'] ?? '');
    $courierLabel = (string) ($order['courier'] ?? '');
    $trackingUrl = '';
    if ($trackingNumber !== '') {
        $trackingUrl = $courier->buildTrackingUrl($courierLabel, $trackingNumber);
    }
    if ($trackingUrl === '') {
        $trackingUrl = trim((string) ($order['tracking_url'] ?? ''));
    }

    echo json_encode([
        'status' => 'success',
        'tracking_url' => $trackingUrl,
        'tracking_status' => $order['tracking_status'] ?? '',
        'courier' => $courierLabel,
        'tracking_number' => $trackingNumber,
        'events' => $courier->getTimeline($orderId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'sync') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $result = $courier->syncOrderTracking($orderId);
    echo json_encode(['status' => 'success', 'sync' => $result, 'events' => $courier->getTimeline($orderId)]);
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
