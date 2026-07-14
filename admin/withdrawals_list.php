<?php
require 'admin_check.php';
require_once '../includes/WalletService.php';
require_once '../models/Wallet.php';

$csrf_token = getCsrfToken();
$walletService = new WalletService($conn);
$walletModel = new Wallet($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin = $_SESSION['user'];

    if ($action === 'approve' && $requestId > 0) {
        if ($walletService->completeWithdrawal($requestId, $admin)) {
            echo "<script>alert('✅ อนุมัติถอนเงินแล้ว (รอโอนจริงผ่านธนาคาร)'); location.href='withdrawals_list.php';</script>";
        } else {
            echo "<script>alert('❌ อนุมัติไม่สำเร็จ'); location.href='withdrawals_list.php';</script>";
        }
        exit;
    }
    if ($action === 'reject' && $requestId > 0) {
        $note = trim($_POST['admin_note'] ?? 'ปฏิเสธโดยแอดมิน');
        if ($walletService->rejectWithdrawal($requestId, $admin, $note)) {
            echo "<script>alert('คืนเงินเข้ากระเป๋าแล้ว'); location.href='withdrawals_list.php';</script>";
        }
        exit;
    }
}

$withdrawals = $walletModel->getAllWithdrawals(100);
$page_title = 'คำขอถอนเงิน';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>💸 คำขอถอนเงิน</h1>
                <p>อนุมัติแล้วให้โอนเงินจริงผ่านธนาคาร (manual transfer)</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ผู้ใช้</th>
                            <th>จำนวน</th>
                            <th>ธนาคาร</th>
                            <th>บัญชี</th>
                            <th>สถานะ</th>
                            <th>วันที่</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr>
                            <td>#<?php echo (int)$w['id']; ?></td>
                            <td><?php echo safeText($w['username']); ?></td>
                            <td class="money">฿<?php echo number_format((float)$w['amount'], 2); ?></td>
                            <td><?php echo safeText($w['bank_name']); ?></td>
                            <td><?php echo safeText($w['account_name']); ?> (<?php echo safeText($w['account_number_masked']); ?>)</td>
                            <td>
                                <?php if ($w['status'] === 'pending'): ?>
                                    <span class="admin-badge admin-badge-pending">รออนุมัติ</span>
                                <?php elseif ($w['status'] === 'completed'): ?>
                                    <span class="admin-badge admin-badge-done">สำเร็จ</span>
                                <?php else: ?>
                                    <?php echo safeText($w['status']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="admin-text-muted"><?php echo safeText($w['created_at']); ?></td>
                            <td>
                                <?php if ($w['status'] === 'pending'): ?>
                                <div class="admin-action-group">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$w['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="admin-btn admin-btn-success admin-btn-sm" onclick="return confirm('อนุมัติถอนเงิน?')">อนุมัติ</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$w['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" onclick="return confirm('ปฏิเสธและคืนเงินเข้ากระเป๋า?')">ปฏิเสธ</button>
                                    </form>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($withdrawals)): ?>
                        <tr><td colspan="8" class="empty">ยังไม่มีคำขอถอน</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
