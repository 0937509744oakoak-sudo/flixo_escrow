<?php
require 'admin_check.php';
require_once '../includes/order_helper.php';
require_once '../includes/OrderStateMachine.php'; // 🔥 เรียกผู้คุมกฎ
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
            echo "<script>alert('❌ ไม่สามารถอนุมัติได้: " . $e->getMessage() . "'); window.location='escrow_list.php';</script>";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $oid = (int)$_POST['order_id'];
    $decision = $_POST['decision']; // ค่าที่รับมา: 'transfer_to_seller' หรือ 'refund_to_buyer'
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
        echo "<script>alert('❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "'); window.location='escrow_list.php';</script>";
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
                <p>อนุมัติสลิปโอนเงินที่อยู่ในสถานะ WAITING_VERIFY</p>
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
                            $slipUrl = '../uploads/slips/' . rawurlencode($row['slip_image']);
                            echo "<tr>
                                <td>#" . safeText($row['id']) . "</td>
                                <td>
                                    <a href='{$slipUrl}' target='_blank'>
                                        <img src='{$slipUrl}' class='admin-slip-thumb' alt='Slip'>
                                    </a>
                                </td>
                                <td class='money'>฿" . number_format((float)$row['amount'], 2) . "</td>
                                <td>
                                    <small class='admin-text-muted'>จาก: " . safeText($row['buyer_name']) . "</small><br>
                                    <small class='admin-text-muted'>ถึง: " . safeText($row['seller_name']) . "</small>
                                </td>
                                <td>" . safeText(date('d/m H:i', strtotime($row['paid_at']))) . "</td>
                                <td>
                                    <form method='post' action='escrow_list.php' style='display:inline;margin:0;'>
                                        <input type='hidden' name='approve_id' value='" . safeText($row['id']) . "'>
                                        <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                        <button type='submit' class='admin-btn admin-btn-success' onclick='return confirm(\"ยอดเงินเข้าบัญชีจริงแล้วใช่ไหม?\")'>✅ ยืนยันยอด</button>
                                    </form>
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