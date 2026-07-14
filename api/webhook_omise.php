<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/omise_helper.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$event = json_decode($raw, true);

if (!is_array($event) || ($event['object'] ?? '') !== 'event') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event']);
    exit;
}

$key = $event['key'] ?? '';
$data = $event['data'] ?? [];
$object = $data['object'] ?? [];

try {
    if (in_array($key, ['charge.complete', 'charge.create'], true) && ($object['object'] ?? '') === 'charge') {
        $metadata = $object['metadata'] ?? [];
        $orderId = (int)($metadata['order_id'] ?? 0);
        if ($orderId > 0 && !empty($object['paid'])) {
            $type = ($object['source']['type'] ?? '') === 'promptpay' ? 'promptpay' : 'card';
            markOrderPaidFromOmise($conn, $orderId, $object, $type);
        }
    }

    if ($key === 'source.chargeable' && ($object['object'] ?? '') === 'source') {
        $metadata = $object['metadata'] ?? [];
        $orderId = (int)($metadata['order_id'] ?? 0);
        $sourceId = $object['id'] ?? '';
        if ($orderId > 0 && $sourceId !== '') {
            processOmisePromptPaySource($conn, $orderId, $sourceId);
        }
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    error_log('Omise webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
