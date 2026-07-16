<?php
// includes/CourierService.php — logistics tracking URLs + shipment events

class CourierService {
    private mysqli $conn;

    private const COURIERS = [
        'Thailand Post' => ['code' => 'thaipost', 'url' => 'https://track.thailandpost.co.th/?trackNumber=%s', 'aliases' => ['ไปรษณีย์ไทย', 'thailand post']],
        'Kerry Express' => ['code' => 'kerry', 'url' => 'https://th.kex-express.com/th/track/', 'aliases' => ['kerry', 'kex']],
        'Flash Express' => ['code' => 'flash', 'url' => 'https://www.flashexpress.co.th/fle/tracking', 'aliases' => ['flash']],
        'J&T Express' => ['code' => 'jnt', 'url' => 'https://www.jtexpress.co.th/service/track', 'aliases' => ['j&t', 'j&t express', 'jnt']],
        'Ninja Van' => ['code' => 'ninja', 'url' => 'https://www.ninjavan.co/th-th/tracking', 'aliases' => ['ninja']],
        'DHL' => ['code' => 'dhl', 'url' => 'https://www.dhl.com/th-th/home/tracking.html', 'aliases' => ['dhl']],
        'Other' => ['code' => 'other', 'url' => '', 'aliases' => ['อื่นๆ', 'other']],
    ];

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    private static function resolveCourier(string $courierLabel): ?array {
        $needle = strtolower(trim($courierLabel));
        if ($needle === '') {
            return null;
        }

        foreach (self::COURIERS as $label => $meta) {
            if (strcasecmp($label, $courierLabel) === 0) {
                return $meta + ['label' => $label];
            }
            foreach ($meta['aliases'] ?? [] as $alias) {
                if ($needle === strtolower($alias) || str_contains($needle, strtolower($alias))) {
                    return $meta + ['label' => $label];
                }
            }
        }

        return null;
    }

    public static function normalizeCourierCode(string $courierLabel): string {
        $meta = self::resolveCourier($courierLabel);
        return $meta['code'] ?? 'other';
    }

    public function buildTrackingUrl(string $courierLabel, string $trackingNumber): string {
        $meta = self::resolveCourier($courierLabel);
        if (!$meta || ($meta['url'] ?? '') === '') {
            return '';
        }
        return sprintf($meta['url'], rawurlencode($trackingNumber));
    }

    public function onShipmentCreated(int $orderId, string $courier, string $trackingNumber): void {
        $code = self::normalizeCourierCode($courier);
        $url = $this->buildTrackingUrl($courier, $trackingNumber);

        $stmt = $this->conn->prepare(
            "UPDATE orders SET courier_code = ?, tracking_url = ?, shipped_at = COALESCE(shipped_at, NOW()),
             tracking_status = 'จัดส่งแล้ว', tracking_status_code = 'SHIPPED' WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ssi', $code, $url, $orderId);
            $stmt->execute();
        } else {
            error_log('CourierService onShipmentCreated prepare failed: ' . $this->conn->error);
        }

        try {
            $this->addEvent($orderId, 'SHIPPED', 'ผู้ขายแจ้งจัดส่งแล้ว', null, 'manual');
        } catch (Throwable $e) {
            error_log('CourierService addEvent failed: ' . $e->getMessage());
        }

        try {
            $this->syncOrderTracking($orderId);
        } catch (Throwable $e) {
            error_log('CourierService syncOrderTracking failed: ' . $e->getMessage());
        }
    }

    public function addEvent(int $orderId, string $statusCode, string $statusLabel, ?string $location, string $source, ?array $raw = null): void {
        $payload = $raw !== null ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
        $locationValue = $location ?? '';

        $stmt = $this->conn->prepare(
            "INSERT INTO shipment_events (order_id, status_code, status_label, location, event_at, source, raw_payload)
             VALUES (?, ?, ?, NULLIF(?, ''), NOW(), ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('shipment_events unavailable: ' . $this->conn->error);
        }
        $stmt->bind_param('isssss', $orderId, $statusCode, $statusLabel, $locationValue, $source, $payload);
        if (!$stmt->execute()) {
            throw new RuntimeException('shipment_events insert failed: ' . $stmt->error);
        }

        $upd = $this->conn->prepare(
            "UPDATE orders SET tracking_status = ?, tracking_status_code = ?, last_tracking_sync_at = NOW() WHERE id = ?"
        );
        if ($upd) {
            $upd->bind_param('ssi', $statusLabel, $statusCode, $orderId);
            $upd->execute();
        }
    }

    public function getTimeline(int $orderId): array {
        $stmt = $this->conn->prepare(
            "SELECT status_code, status_label, location, event_at, source FROM shipment_events
             WHERE order_id = ? ORDER BY event_at ASC, id ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    /**
     * Poll external tracking API when configured; otherwise no-op after initial event.
     */
    public function syncOrderTracking(int $orderId): array {
        $stmt = $this->conn->prepare(
            "SELECT id, courier, courier_code, tracking_number, tracking_url, tracking_status_code FROM orders WHERE id = ? LIMIT 1"
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'orders_prepare_failed'];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        if (!$order || empty($order['tracking_number'])) {
            return ['ok' => false, 'message' => 'no tracking'];
        }

        $apiKey = getenv('TRACKING_API_KEY') ?: '';
        $apiUrl = getenv('TRACKING_API_URL') ?: '';
        if ($apiKey === '' || $apiUrl === '') {
            return ['ok' => true, 'message' => 'tracking_url_only', 'url' => $order['tracking_url']];
        }

        $payload = [
            'courier' => $order['courier_code'] ?? 'other',
            'tracking_number' => $order['tracking_number'],
        ];
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !$body) {
            return ['ok' => false, 'message' => 'api_error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'invalid_response'];
        }

        $events = $data['events'] ?? [];
        foreach ($events as $ev) {
            $code = (string) ($ev['status_code'] ?? 'UPDATE');
            $label = (string) ($ev['status_label'] ?? 'อัปเดตสถานะ');
            $loc = isset($ev['location']) ? (string) $ev['location'] : null;
            $this->addEvent($orderId, $code, $label, $loc, 'api', $ev);
        }

        if (($data['status_code'] ?? '') === 'DELIVERED') {
            $this->notifyDelivered($orderId);
        }

        return ['ok' => true, 'message' => 'synced', 'events' => count($events)];
    }

    private function notifyDelivered(int $orderId): void {
        $stmt = $this->conn->prepare("SELECT buyer_name FROM orders WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && function_exists('createNotification')) {
            createNotification(
                $this->conn,
                $row['buyer_name'],
                "📦 พัสดุออเดอร์ #{$orderId} จัดส่งถึงแล้ว — กรุณากดยืนยันรับสินค้า",
                "dashboard.php?tab=orders"
            );
        }
    }
}
