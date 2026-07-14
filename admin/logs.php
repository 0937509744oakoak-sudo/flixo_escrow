<?php
require 'admin_check.php';
require_once '../includes/OrderStateMachine.php';

$q = trim($_GET['q'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$attemptsWhere = [];
$statusWhere = [];
$txWhere = [];

if ($q !== '') {
    $qEsc = $conn->real_escape_string(mb_substr($q, 0, 150));
    $attemptsWhere[] = "(a.username LIKE '%$qEsc%' OR a.ip LIKE '%$qEsc%' OR o.buyer_name LIKE '%$qEsc%' OR o.seller_name LIKE '%$qEsc%' OR CAST(a.order_id AS CHAR) LIKE '%$qEsc%')";
    $statusWhere[] = "(changed_by LIKE '%$qEsc%' OR old_status LIKE '%$qEsc%' OR new_status LIKE '%$qEsc%' OR CAST(order_id AS CHAR) LIKE '%$qEsc%' OR reason LIKE '%$qEsc%')";
    $txWhere[] = "(type LIKE '%$qEsc%' OR currency LIKE '%$qEsc%' OR CAST(order_id AS CHAR) LIKE '%$qEsc%' OR meta LIKE '%$qEsc%')";
}

if ($startDate !== '' && validateDate($startDate)) {
    $startDateEsc = $conn->real_escape_string($startDate);
    $attemptsWhere[] = "a.created_at >= '$startDateEsc 00:00:00'";
    $statusWhere[] = "created_at >= '$startDateEsc 00:00:00'";
    $txWhere[] = "created_at >= '$startDateEsc 00:00:00'";
}
if ($endDate !== '' && validateDate($endDate)) {
    $endDateEsc = $conn->real_escape_string($endDate);
    $attemptsWhere[] = "a.created_at <= '$endDateEsc 23:59:59'";
    $statusWhere[] = "created_at <= '$endDateEsc 23:59:59'";
    $txWhere[] = "created_at <= '$endDateEsc 23:59:59'";
}

$sql_attempts = "SELECT a.id, a.order_id, a.username, a.ip, a.created_at, o.buyer_name, o.seller_name, o.amount AS order_amount
                 FROM slip_upload_attempts a
                 LEFT JOIN orders o ON o.id = a.order_id";
if (!empty($attemptsWhere)) {
    $sql_attempts .= " WHERE " . implode(' AND ', $attemptsWhere);
}
$sql_attempts .= " ORDER BY a.created_at DESC LIMIT 30";
$res_attempts = $conn->query($sql_attempts);

$sql_status_logs = "SELECT id, order_id, old_status, new_status, changed_by, reason, created_at
                    FROM order_status_logs";
if (!empty($statusWhere)) {
    $sql_status_logs .= " WHERE " . implode(' AND ', $statusWhere);
}
$sql_status_logs .= " ORDER BY created_at DESC LIMIT 30";
$res_status_logs = $conn->query($sql_status_logs);

$sql_tx_logs = "SELECT id, order_id, type, amount, currency, meta, created_at
                 FROM transaction_logs";
if (!empty($txWhere)) {
    $sql_tx_logs .= " WHERE " . implode(' AND ', $txWhere);
}
$sql_tx_logs .= " ORDER BY created_at DESC LIMIT 30";
$res_tx_logs = $conn->query($sql_tx_logs);
$page_title = 'Admin Logs';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>📋 Admin Logs</h1>
                <p>ประวัติการอัปโหลดสลิป, สถานะออเดอร์ และธุรกรรมล่าสุด</p>
            </div>
        </div>

        <div class="admin-filter-bar">
            <form method="get" action="logs.php" class="admin-filter-form">
                <div class="admin-form-group" style="flex:2;">
                    <label>ค้นหา</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="username / order id / type...">
                </div>
                <div class="admin-form-group">
                    <label>วันที่เริ่ม</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="admin-form-group">
                    <label>วันที่สิ้นสุด</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="admin-form-group" style="flex:0;min-width:120px;">
                    <label>&nbsp;</label>
                    <button type="submit" class="admin-btn admin-btn-primary" style="width:100%;padding:10px;">กรอง</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <h3 class="admin-section-title">📤 Slip Upload Attempts</h3>
            <div class="admin-panel">
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Order</th>
                                <th>Username</th>
                                <th>IP</th>
                                <th>Order Amount</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($res_attempts && $res_attempts->num_rows > 0): ?>
                            <?php while ($row = $res_attempts->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo $row['order_id'] ? '#'.$row['order_id'] : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td class="admin-text-muted"><?php echo htmlspecialchars($row['ip']); ?></td>
                                    <td class="money"><?php echo $row['order_amount'] ? '฿'.number_format($row['order_amount'], 2) : '-'; ?></td>
                                    <td class="admin-text-muted"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty">ไม่มีการพยายามอัปโหลดสลิปล่าสุด</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h3 class="admin-section-title">🧾 Order Status Logs</h3>
            <div class="admin-panel">
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Order</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Changed By</th>
                                <th>Reason</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($res_status_logs && $res_status_logs->num_rows > 0): ?>
                            <?php while ($row = $res_status_logs->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td>#<?php echo $row['order_id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['old_status']); ?></td>
                                    <td><?php echo htmlspecialchars($row['new_status']); ?></td>
                                    <td><?php echo htmlspecialchars($row['changed_by']); ?></td>
                                    <td><?php echo htmlspecialchars($row['reason']); ?></td>
                                    <td class="admin-text-muted"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="empty">ไม่มีประวัติการเปลี่ยนสถานะล่าสุด</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h3 class="admin-section-title">💳 Transaction Logs</h3>
            <div class="admin-panel">
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Order</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Currency</th>
                                <th>Meta</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($res_tx_logs && $res_tx_logs->num_rows > 0): ?>
                            <?php while ($row = $res_tx_logs->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo $row['order_id'] ? '#'.$row['order_id'] : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($row['type']); ?></td>
                                    <td class="money"><?php echo $row['amount'] !== null ? '฿'.number_format($row['amount'], 2) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($row['currency']); ?></td>
                                    <td class="admin-text-muted"><?php echo htmlspecialchars($row['meta']); ?></td>
                                    <td class="admin-text-muted"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="empty">ไม่มีประวัติธุรกรรมล่าสุด</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
