<?php
require 'admin_check.php';
require_once '../includes/order_helper.php';
require_once '../includes/OrderStateMachine.php';
require_once '../models/Order.php';

$csrf_token = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $oid = (int)$_POST['approve_id'];
    $orderModel = new Order($conn);
    $order = $orderModel->findById($oid);

    $currentUser = ['username' => $_SESSION['user'], 'role' => 'admin'];

    if ($order) {
        try {
            OrderStateMachine::validate($order, OrderStateMachine::PAID, $currentUser);
            $orderModel->changeStatus($oid, $order['status'], OrderStateMachine::PAID, $currentUser['username'], 'แอดมินยืนยันยอดเงินเข้าบัญชีจริง');
            $msg = "💰 ยอดเงินได้รับการอนุมัติแล้ว! กรุณาจัดส่งสินค้าและแจ้งเลขพัสดุ";
            createNotification($conn, $order['seller_name'], $msg, "dashboard.php?tab=orders");
            echo "<script>alert('✅ อนุมัติยอดเงินเรียบร้อย!'); window.location='escrow_list.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('❌ ไม่สามารถอนุมัติได้: " . addslashes($e->getMessage()) . "'); window.location='escrow_list.php';</script>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $oid = (int) $_POST['reject_id'];
    $reason = trim((string) ($_POST['reject_reason'] ?? 'สลิปไม่ถูกต้องหรืออ่านไม่ได้'));
    $orderModel = new Order($conn);
    $order = $orderModel->findById($oid);
    $currentUser = ['username' => $_SESSION['user'], 'role' => 'admin'];

    if ($order) {
        try {
            OrderStateMachine::validate($order, OrderStateMachine::CANCELLED, $currentUser);
            $orderModel->changeStatus($oid, $order['status'], OrderStateMachine::CANCELLED, $currentUser['username'], 'แอดมินปฏิเสธสลิป: ' . $reason);
            createNotification(
                $conn,
                $order['buyer_name'],
                "❌ สลิปออเดอร์ #{$oid} ไม่ผ่านการตรวจสอบ\nเหตุผล: {$reason}\nกรุณาสร้างคำสั่งซื้อใหม่หรือติดต่อฝ่ายสนับสนุน",
                'dashboard.php?tab=orders'
            );
            createNotification(
                $conn,
                $order['seller_name'],
                "ℹ️ ออเดอร์ #{$oid} ถูกยกเลิกเนื่องจากสลิปไม่ผ่านการตรวจสอบ",
                'dashboard.php?tab=orders'
            );
            echo "<script>alert('ปฏิเสธสลิปและยกเลิกออเดอร์แล้ว'); window.location='escrow_list.php';</script>";
        } catch (Exception $e) {
            echo "<script>alert('❌ ไม่สามารถปฏิเสธได้: " . addslashes($e->getMessage()) . "'); window.location='escrow_list.php';</script>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $oid = (int)$_POST['order_id'];
    $decision = $_POST['decision'];
    $admin_reason = trim($_POST['admin_reason']);

    $orderModel = new Order($conn);
    $order = $orderModel->findById($oid);
    
    $currentUser = ['username' => $_SESSION['user'], 'role' => 'admin'];
    $newStatus = ($decision === 'transfer_to_seller') ? OrderStateMachine::COMPLETED : OrderStateMachine::CANCELLED;

    try {
        OrderStateMachine::validate($order, $newStatus, $currentUser);
        $orderModel->changeStatus($oid, $order['status'], $newStatus, $currentUser['username'], "แอดมินตัดสิน: " . $admin_reason);

        $msg_buyer = ($newStatus === OrderStateMachine::COMPLETED) ? "❌ แอดมินตัดสินให้ผู้ขายชนะ (เงินโอนให้ผู้ขาย)" : "✅ แอดมินตัดสินให้ผู้ซื้อชนะ (คืนเงิน)";
        $msg_seller = ($newStatus === OrderStateMachine::COMPLETED) ? "✅ แอดมินตัดสินให้ผู้ขายชนะ (เงินเข้าบัญชีคุณ)" : "❌ แอดมินตัดสินคืนเงินให้ผู้ซื้อ";
        
        createNotification($conn, $order['buyer_name'], $msg_buyer . " ออเดอร์ #$oid", "dashboard.php?tab=orders");
        createNotification($conn, $order['seller_name'], $msg_seller . " ออเดอร์ #$oid", "earnings.php");

        echo "<script>alert('บันทึกคำตัดสินสำเร็จ!'); window.location='escrow_list.php';</script>";
    } catch (Exception $e) {
        echo "<script>alert('❌ เกิดข้อผิดพลาด: " . addslashes($e->getMessage()) . "'); window.location='escrow_list.php';</script>";
    }
}
$page_title = 'จัดการ Escrow';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>💰 รายการรอตรวจสอบยอดเงิน</h1>
                <p>อนุมัติสลิปโอนเงินที่อยู่ในสถานะ WAITING_VERIFY — กดรูปเพื่อดูเต็มจอ</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>หลักฐาน (Slip)</th>
                            <th>ยอดโอน</th>
                            <th>ผู้ซื้อ → ผู้ขาย</th>
                            <th>เวลาแจ้ง</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $stmt = $conn->prepare("SELECT id, slip_image, amount, buyer_name, seller_name, paid_at FROM orders WHERE status = ? ORDER BY paid_at ASC");
                    $status = OrderStateMachine::WAITING_VERIFY;
                    $stmt->bind_param("s", $status);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $slipUrl = orderSlipUrl($row['slip_image'] ?? '');
                            $slipCell = '<span class="admin-text-muted">ไม่มีไฟล์สลิป</span>';
                            if ($slipUrl !== '') {
                                $safeUrl = htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8');
                                $slipCell = "<a href='{$safeUrl}' target='_blank' rel='noopener' title='เปิดดูสลิปเต็ม'>"
                                    . "<img src='{$safeUrl}' class='admin-slip-thumb' alt='Slip' "
                                    . "style='max-width:140px;max-height:180px;object-fit:contain;border-radius:8px;border:1px solid #e2e8f0;background:#fff;'>"
                                    . "</a>"
                                    . "<div style='margin-top:6px;'><a href='{$safeUrl}' target='_blank' rel='noopener'>เปิดรูปเต็ม →</a></div>";
                            }
                            $paidAt = !empty($row['paid_at']) ? date('d/m H:i', strtotime($row['paid_at'])) : '-';
                            echo "<tr>
                                <td>#" . safeText($row['id']) . "</td>
                                <td>{$slipCell}</td>
                                <td class='money'>฿" . number_format((float)$row['amount'], 2) . "</td>
                                <td>
                                    <small class='admin-text-muted'>จาก: " . safeText($row['buyer_name']) . "</small><br>
                                    <small class='admin-text-muted'>ถึง: " . safeText($row['seller_name']) . "</small>
                                </td>
                                <td>" . safeText($paidAt) . "</td>
                                <td>
                                    <div class='admin-action-group' style='flex-direction:column;align-items:stretch;gap:8px;'>
                                        <form method='post' action='escrow_list.php' style='margin:0;'>
                                            <input type='hidden' name='approve_id' value='" . (int)$row['id'] . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <button type='submit' class='admin-btn admin-btn-success' onclick='return confirm(\"ยอดเงินเข้าบัญชีจริงแล้วใช่ไหม?\")'>✅ ยืนยันยอด</button>
                                        </form>
                                        <form method='post' action='escrow_list.php' style='margin:0;' onsubmit='return confirm(\"ปฏิเสธสลิปและยกเลิกออเดอร์นี้?\");'>
                                            <input type='hidden' name='reject_id' value='" . (int)$row['id'] . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <input type='text' name='reject_reason' placeholder='เหตุผลที่ปฏิเสธ' style='width:100%;margin-bottom:6px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px;'>
                                            <button type='submit' class='admin-btn admin-btn-danger admin-btn-sm'>❌ ปฏิเสธสลิป</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='empty'>✅ ไม่มียอดค้างตรวจสอบ</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
