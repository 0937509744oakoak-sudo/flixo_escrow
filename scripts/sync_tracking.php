#!/usr/bin/env php
<?php
/**
 * Cron: sync logistics tracking for shipped orders.
 * Usage: php scripts/sync_tracking.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/CourierService.php';

$courier = new CourierService($conn);
$res = $conn->query(
    "SELECT id FROM orders WHERE status = 'SHIPPED' AND tracking_number IS NOT NULL AND tracking_number != '' ORDER BY id DESC LIMIT 100"
);
$synced = 0;
while ($row = $res->fetch_assoc()) {
    $result = $courier->syncOrderTracking((int) $row['id']);
    if (!empty($result['ok'])) {
        $synced++;
    }
    echo "Order #{$row['id']}: " . ($result['message'] ?? 'done') . "\n";
}
echo "Synced {$synced} orders.\n";
