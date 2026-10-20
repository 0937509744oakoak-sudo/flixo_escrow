<?php
/**
 * api/webhook_omise.php — Omise event receiver.
 *
 * Omise does not sign webhook requests, so the body is treated purely as a
 * notification hint: we take the object id from it and re-fetch the real object
 * from the Omise API before touching an order. Without that, anyone could POST
 * {"paid":true, metadata:{order_id:N}} and mark an arbitrary order as paid.
 */
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

// Fail closed: with no secret key we cannot verify anything, so we must not act.
if (!OmiseService::isConfigured()) {
    ErrorLogger::write('error', 'Omise webhook received but OMISE_SECRET_KEY is not configured');
    http_response_code(503);
    echo json_encode(['error' => 'omise not configured']);
    exit;
}

$key = (string) ($event['key'] ?? '');
$object = $event['data']['object'] ?? [];
$objectId = (string) ($object['id'] ?? '');

try {
    $omise = new OmiseService();

    if (in_array($key, ['charge.complete', 'charge.create'], true) && str_starts_with($objectId, 'chrg')) {
        $charge = $omise->retrieveCharge($objectId);
        $orderId = (int) ($charge['metadata']['order_id'] ?? 0);
        if ($orderId > 0 && $omise->isPaid($charge)) {
            $type = (($charge['source']['type'] ?? '') === 'promptpay') ? 'promptpay' : 'card';
            markOrderPaidFromOmise($conn, $orderId, $charge, $type);
        }
    }

    if ($key === 'source.chargeable' && str_starts_with($objectId, 'src')) {
        // processOmisePromptPaySource() re-retrieves the source itself, but the
        // order it belongs to still has to come from Omise rather than the body.
        $source = $omise->retrieveSource($objectId);
        $orderId = (int) ($source['metadata']['order_id'] ?? 0);
        if ($orderId > 0) {
            processOmisePromptPaySource($conn, $orderId, $objectId);
        }
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (Throwable $e) {
    ErrorLogger::write('error', 'Omise webhook: ' . $e->getMessage(), ['event_key' => $key, 'object_id' => $objectId]);
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
