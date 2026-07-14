<?php
require 'admin_check.php';
require_once '../models/Order.php';
require_once 'admin_helpers.php';

$page_title = 'แดชบอร์ดผู้ดูแลระบบ';

// Auto cancel pending payments เกิน 24 ชม.
$orderModel = new Order($conn);
$orderModel->cancelExpiredPendingOrders(24);

$stats = adminGetDashboardStats($conn);
$pendingEscrow = adminGetPendingEscrow($conn, 6);
$recentOrders = adminGetRecentOrders($conn, 8);
$statusBreakdown = adminGetOrderStatusBreakdown($conn);
$maxStatusCount = max(1, ...array_column($statusBreakdown, 'cnt'));

$totalActionItems = $stats['waiting_verify']
    + $stats['pending_withdrawals']
    + $stats['open_disputes']
    + $stats['fraud_queue']
    + $stats['chat_moderation_queue']
    + $stats['support_unread'];

include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>📊 แดชบอร์ดผู้ดูแลระบบ</h1>
                <p>ภาพรวมธุรกรรม Escrow, สมาชิก และงานที่รอดำเนินการ</p>
            </div>
            <div class="admin-topbar-meta">
                อัปเดต: <?php echo date('d/m/Y H:i'); ?><br>
                <?php if ($totalActionItems > 0): ?>
                    <span style="color:var(--admin-danger);font-weight:600;">
                        ⚡ มี <?php echo $totalActionItems; ?> รายการรอดำเนินการ
                    </span>
                <?php else: ?>
                    <span style="color:var(--admin-success);">✅ ไม่มีงานค้าง</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="admin-kpi-grid">
            <div class="admin-kpi kpi-escrow">
                <div class="admin-kpi-label">เงินในถังพัก (Escrow)</div>
                <div class="admin-kpi-value" style="color:var(--admin-warning);">
                    <?php echo adminFormatMoney($stats['escrow_balance']); ?>
                </div>
                <div class="admin-kpi-sub">ออเดอร์ PAID + SHIPPED</div>
            </div>
            <div class="admin-kpi kpi-gmv">
                <div class="admin-kpi-label">ยอดซื้อขายสะสม (GMV)</div>
                <div class="admin-kpi-value" style="color:var(--admin-success);">
                    <?php echo adminFormatMoney($stats['gmv']); ?>
                </div>
                <div class="admin-kpi-sub">ออเดอร์ COMPLETED ทั้งหมด</div>
            </div>
            <div class="admin-kpi kpi-users">
                <div class="admin-kpi-label">สมาชิกทั้งหมด</div>
                <div class="admin-kpi-value"><?php echo number_format($stats['total_users']); ?></div>
                <div class="admin-kpi-sub">+<?php echo $stats['new_users_month']; ?> คนเดือนนี้</div>
            </div>
            <div class="admin-kpi kpi-orders">
                <div class="admin-kpi-label">คำสั่งซื้อทั้งหมด</div>
                <div class="admin-kpi-value"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="admin-kpi-sub"><?php echo $stats['orders_today']; ?> รายการวันนี้</div>
            </div>
            <div class="admin-kpi kpi-products">
                <div class="admin-kpi-label">สินค้าที่เปิดขาย</div>
                <div class="admin-kpi-value"><?php echo number_format($stats['active_products']); ?></div>
                <div class="admin-kpi-sub">สถานะ active</div>
            </div>
            <div class="admin-kpi kpi-today">
                <div class="admin-kpi-label">ปิดการขายวันนี้</div>
                <div class="admin-kpi-value"><?php echo $stats['completed_today']; ?></div>
                <div class="admin-kpi-sub">ออเดอร์ COMPLETED วันนี้</div>
            </div>
        </div>

        <!-- Action Queue -->
        <div class="admin-action-grid">
            <a href="escrow_list.php" class="admin-action-card<?php echo $stats['waiting_verify'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">💰</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['waiting_verify']; ?></strong>
                    <span>รอตรวจสอบสลิป</span>
                </div>
            </a>
            <a href="withdrawals_list.php" class="admin-action-card<?php echo $stats['pending_withdrawals'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">💸</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['pending_withdrawals']; ?></strong>
                    <span>คำขอถอนเงิน</span>
                </div>
            </a>
            <a href="dispute_list.php" class="admin-action-card<?php echo $stats['open_disputes'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">⚠️</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['open_disputes']; ?></strong>
                    <span>ข้อพิพาทเปิดอยู่</span>
                </div>
            </a>
            <a href="product_fraud_queue.php" class="admin-action-card<?php echo $stats['fraud_queue'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">🖼️</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['fraud_queue']; ?></strong>
                    <span>รูปสินค้ารอตรวจ</span>
                </div>
            </a>
            <a href="chat_moderation_queue.php" class="admin-action-card<?php echo $stats['chat_moderation_queue'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">🔍</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['chat_moderation_queue']; ?></strong>
                    <span>แชทรอตรวจ</span>
                </div>
            </a>
            <a href="support_inbox.php" class="admin-action-card<?php echo $stats['support_unread'] > 0 ? ' has-items' : ''; ?>">
                <div class="admin-action-icon">💬</div>
                <div class="admin-action-info">
                    <strong><?php echo $stats['support_unread']; ?></strong>
                    <span>ข้อความช่วยเหลือใหม่</span>
                </div>
            </a>
        </div>

        <!-- Panels -->
        <div class="admin-panel-grid">
            <!-- Pending Escrow -->
            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h3>⏳ ธุรกรรม Escrow ที่กำลังดำเนินการ</h3>
                    <a href="escrow_list.php">ดูทั้งหมด →</a>
                </div>
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>สินค้า</th>
                                <th>ยอดเงิน</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (count($pendingEscrow) > 0): ?>
                            <?php foreach ($pendingEscrow as $row): ?>
                            <tr>
                                <td>#<?php echo (int)$row['id']; ?></td>
                                <td>
                                    <?php echo safeText($row['item_name']); ?>
                                    <br><small style="color:var(--admin-muted);">
                                        <?php echo safeText($row['buyer_name']); ?> → <?php echo safeText($row['seller_name']); ?>
                                    </small>
                                </td>
                                <td class="money"><?php echo adminFormatMoney((float)$row['amount']); ?></td>
                                <td><?php echo adminStatusBadge($row['status']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty">✅ ไม่มีรายการค้าง — เคลียร์หมดแล้ว</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Order Status Breakdown -->
            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h3>📈 สถานะออเดอร์ทั้งหมด</h3>
                </div>
                <div class="admin-status-list">
                    <?php if (count($statusBreakdown) > 0): ?>
                        <?php foreach ($statusBreakdown as $row): ?>
                        <?php
                            $cnt = (int)$row['cnt'];
                            $pct = round(($cnt / $maxStatusCount) * 100);
                            $color = OrderStateMachine::getColor($row['status']);
                        ?>
                        <div class="admin-status-row">
                            <div class="admin-status-label"><?php echo safeText(OrderStateMachine::getLabel($row['status'])); ?></div>
                            <div class="admin-status-bar-wrap">
                                <div class="admin-status-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div>
                            </div>
                            <div class="admin-status-count"><?php echo $cnt; ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color:var(--admin-muted);text-align:center;padding:20px 0;">ยังไม่มีออเดอร์</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div class="admin-panel">
            <div class="admin-panel-header">
                <h3>🕐 ออเดอร์ล่าสุด</h3>
            </div>
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>สินค้า</th>
                            <th>ผู้ซื้อ</th>
                            <th>ผู้ขาย</th>
                            <th>ยอดเงิน</th>
                            <th>สถานะ</th>
                            <th>วันที่</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($recentOrders) > 0): ?>
                        <?php foreach ($recentOrders as $row): ?>
                        <tr>
                            <td>#<?php echo (int)$row['id']; ?></td>
                            <td><?php echo safeText($row['item_name']); ?></td>
                            <td><?php echo safeText($row['buyer_name']); ?></td>
                            <td><?php echo safeText($row['seller_name']); ?></td>
                            <td class="money"><?php echo adminFormatMoney((float)$row['amount']); ?></td>
                            <td><?php echo adminStatusBadge($row['status']); ?></td>
                            <td style="color:var(--admin-muted);font-size:12px;"><?php echo adminFormatDate($row['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="empty">ยังไม่มีออเดอร์</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>