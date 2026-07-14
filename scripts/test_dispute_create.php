<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../models/Dispute.php';

$dispute = new Dispute($conn);
$row = $conn->query("SELECT id FROM orders WHERE status = 'SHIPPED' LIMIT 1")->fetch_assoc();

if (!$row) {
    echo "No SHIPPED order — create one manually first.\n";
    exit(0);
}

$orderId = (int)$row['id'];
echo "Testing Dispute::create for order #{$orderId}\n";

try {
    $id = $dispute->create($orderId, 'cli_test', 'ไม่ได้รับสินค้า', 'ทดสอบระบบ');
    if ($id) {
        echo "OK — dispute id: {$id}\n";
        $conn->query("DELETE FROM disputes WHERE id = {$id}");
        echo "Cleaned up test dispute.\n";
    } else {
        echo "FAIL — " . $conn->error . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION — " . $e->getMessage() . "\n";
}
