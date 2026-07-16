<?php
require 'admin_check.php';
adminRequirePermission('kyc.review');

$res = $conn->query("SELECT e.*, u.username FROM ekyc_sessions e JOIN users u ON u.id = e.user_id ORDER BY e.created_at DESC LIMIT 100");
$page_title = 'eKYC Logs';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>🪪 eKYC Session Logs</h1>
                <p>ประวัติการยืนยันตัวตนผ่าน Sumsub และระบบ eKYC</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>ผู้ใช้</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>ผล</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($res): while ($row = $res->fetch_assoc()): ?>
                        <tr>
                            <td class="admin-text-muted"><?php echo safeText($row['created_at']); ?></td>
                            <td><?php echo safeText($row['username']); ?></td>
                            <td><?php echo safeText($row['provider']); ?></td>
                            <td><?php echo safeText($row['status']); ?></td>
                            <td><?php echo safeText($row['review_answer'] ?? '-'); ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="empty">ยังไม่มีบันทึก</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
