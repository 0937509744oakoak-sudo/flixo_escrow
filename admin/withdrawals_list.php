<?php
require 'admin_check.php';
require_once '../includes/WalletService.php';
require_once '../includes/order_helper.php';
require_once '../models/Wallet.php';

Rbac::requirePermission($conn, $_SESSION['user'], 'withdrawal.manage');

$csrf_token = getCsrfToken();
$walletService = new WalletService($conn);
$walletModel = new Wallet($conn);
$walletModel->ensurePayoutSlipColumn();
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $admin = $_SESSION['user'];

    if ($action === 'approve' && $requestId > 0) {
        $note = trim($_POST['admin_note'] ?? '');
        $slipName = null;

        if (empty($_FILES['payout_slip']['name']) || ($_FILES['payout_slip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $flash = 'error|กรุณาแนบสลิปการโอนก่อนอนุมัติ';
        } else {
            try {
                $targetDir = flixoUploadsSubdir('withdrawal_slips');
                // PRIVATE object key (public=false) or local filename fallback.
                $slipName = secureUploadCloud($_FILES['payout_slip'], 'withdrawal_slips', $targetDir, 5242880, false);
                if ($walletService->completeWithdrawal($requestId, $admin, $slipName, $note)) {
                    $flash = 'ok|อนุมัติถอนเงินและแนบสลิปให้ผู้ใช้แล้ว';
                } else {
                    // Roll back the just-stored slip (local file or cloud object).
                    if (is_file($targetDir . $slipName)) {
                        @unlink($targetDir . $slipName);
                    } else {
                        require_once __DIR__ . '/../includes/ObjectStorage.php';
                        (new ObjectStorage())->delete($slipName);
                    }
                    $flash = 'error|อนุมัติไม่สำเร็จ (อาจถูกจัดการไปแล้ว)';
                }
            } catch (Throwable $e) {
                $flash = 'error|' . $e->getMessage();
            }
        }
    } elseif ($action === 'reject' && $requestId > 0) {
        $note = trim($_POST['admin_note'] ?? '');
        if ($note === '') {
            $note = 'ปฏิเสธโดยแอดมิน';
        }
        if ($walletService->rejectWithdrawal($requestId, $admin, $note)) {
            $flash = 'ok|ปฏิเสธคำขอและคืนเงินเข้ากระเป๋าแล้ว';
        } else {
            $flash = 'error|ปฏิเสธไม่สำเร็จ';
        }
    }

    if ($flash !== '') {
        $_SESSION['withdrawals_flash'] = $flash;
    }
    header('Location: withdrawals_list.php');
    exit;
}

if (!empty($_SESSION['withdrawals_flash'])) {
    $flash = $_SESSION['withdrawals_flash'];
    unset($_SESSION['withdrawals_flash']);
}

$withdrawals = $walletModel->getAllWithdrawals(100, true);
$page_title = 'คำขอถอนเงิน';
include 'layout_head.php';

$statusLabels = [
    'pending' => ['รออนุมัติ', 'admin-badge-pending'],
    'processing' => ['กำลังดำเนินการ', 'admin-badge-pending'],
    'completed' => ['สำเร็จ', 'admin-badge-done'],
    'rejected' => ['ปฏิเสธ', 'admin-badge-rejected'],
];
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>💸 คำขอถอนเงิน</h1>
                <p>โอนเงินจริงผ่านธนาคารด้วยมือ แล้วแนบสลิปตอนอนุมัติเพื่อส่งให้ผู้ใช้</p>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <?php
            [$flashType, $flashMsg] = array_pad(explode('|', $flash, 2), 2, '');
            $flashClass = $flashType === 'ok' ? 'admin-alert-success' : 'admin-alert-error';
            ?>
            <div class="admin-alert <?php echo $flashClass; ?>" style="margin-bottom:16px;padding:12px 16px;border-radius:10px;background:<?php echo $flashType === 'ok' ? '#ecfdf5' : '#fef2f2'; ?>;color:<?php echo $flashType === 'ok' ? '#065f46' : '#991b1b'; ?>;">
                <?php echo safeText($flashMsg); ?>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ผู้ใช้</th>
                            <th>จำนวน</th>
                            <th>โอนไปที่</th>
                            <th>สถานะ</th>
                            <th>วันที่</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <?php
                        $st = $statusLabels[$w['status']] ?? [$w['status'], ''];
                        $acct = $w['account_number'] ?? $w['account_number_masked'];
                        ?>
                        <tr>
                            <td>#<?php echo (int)$w['id']; ?></td>
                            <td>@<?php echo safeText($w['username']); ?></td>
                            <td class="money">฿<?php echo number_format((float)$w['amount'], 2); ?></td>
                            <td>
                                <div><strong><?php echo safeText($w['bank_name']); ?></strong></div>
                                <div><?php echo safeText($w['account_name']); ?></div>
                                <div style="font-family:monospace;letter-spacing:0.04em;"><?php echo safeText($acct); ?></div>
                            </td>
                            <td>
                                <span class="admin-badge <?php echo safeText($st[1]); ?>"><?php echo safeText($st[0]); ?></span>
                                <?php if ($w['status'] === 'completed' && !empty($w['payout_slip'])): ?>
                                    <div style="margin-top:6px;">
                                        <a href="<?php echo safeText(withdrawalSlipUrl((int)$w['id'])); ?>" target="_blank" class="admin-btn admin-btn-muted admin-btn-sm">ดูสลิป</a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($w['status'] === 'rejected' && !empty($w['admin_note'])): ?>
                                    <div class="admin-text-muted" style="margin-top:4px;font-size:12px;"><?php echo safeText($w['admin_note']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="admin-text-muted"><?php echo safeText($w['created_at']); ?></td>
                            <td>
                                <?php if ($w['status'] === 'pending'): ?>
                                <div class="admin-action-group" style="flex-direction:column;align-items:stretch;gap:10px;min-width:220px;">
                                    <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:6px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$w['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <label style="font-size:12px;color:#64748b;">แนบสลิปโอน (บังคับ)</label>
                                        <input type="file" name="payout_slip" accept="image/jpeg,image/png,image/webp" required>
                                        <input type="text" name="admin_note" placeholder="หมายเหตุ (ไม่บังคับ)" maxlength="255" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                                        <button type="submit" class="admin-btn admin-btn-success admin-btn-sm" onclick="return confirm('ยืนยันว่าโอนเงินจริงแล้ว และจะแนบสลิปให้ผู้ใช้?')">✅ อนุมัติ + แนบสลิป</button>
                                    </form>
                                    <form method="post" style="display:flex;flex-direction:column;gap:6px;">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$w['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="text" name="admin_note" placeholder="เหตุผลที่ปฏิเสธ" maxlength="255" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                                        <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm" onclick="return confirm('ปฏิเสธและคืนเงินเข้ากระเป๋า?')">❌ ปฏิเสธ</button>
                                    </form>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($withdrawals)): ?>
                        <tr><td colspan="7" class="empty">ยังไม่มีคำขอถอน</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
