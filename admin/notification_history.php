<?php
require 'admin_check.php';

$q = trim($_GET['q'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$emailStatus = trim($_GET['email_status'] ?? '');

$where = [];
if ($q !== '') {
    $qEsc = $conn->real_escape_string(mb_substr($q, 0, 150));
    $where[] = "(user_name LIKE '%$qEsc%' OR message LIKE '%$qEsc%' OR link_url LIKE '%$qEsc%')";
}
if ($startDate !== '' && validateDate($startDate)) {
    $startDateEsc = $conn->real_escape_string($startDate);
    $where[] = "created_at >= '$startDateEsc 00:00:00'";
}
if ($endDate !== '' && validateDate($endDate)) {
    $endDateEsc = $conn->real_escape_string($endDate);
    $where[] = "created_at <= '$endDateEsc 23:59:59'";
}

$sql_notifications = "SELECT id, user_name, message, link_url, is_read, created_at FROM notifications";
if (!empty($where)) {
    $sql_notifications .= " WHERE " . implode(' AND ', $where);
}
$sql_notifications .= " ORDER BY created_at DESC LIMIT 50";
$res_notifications = $conn->query($sql_notifications);

$emailWhere = [];
if ($q !== '') {
    $qEsc = $conn->real_escape_string($q);
    $emailWhere[] = "(user_name LIKE '%$qEsc%' OR recipient_email LIKE '%$qEsc%' OR subject LIKE '%$qEsc%' OR error_message LIKE '%$qEsc%')";
}
if ($startDate !== '') {
    $startDateEsc = $conn->real_escape_string($startDate);
    $emailWhere[] = "created_at >= '$startDateEsc 00:00:00'";
}
if ($endDate !== '') {
    $endDateEsc = $conn->real_escape_string($endDate);
    $emailWhere[] = "created_at <= '$endDateEsc 23:59:59'";
}
if ($emailStatus !== '' && in_array($emailStatus, ['success', 'failed'], true)) {
    $emailStatusEsc = $conn->real_escape_string($emailStatus);
    $emailWhere[] = "status = '$emailStatusEsc'";
}

$sql_email_events = "SELECT id, user_name, recipient_email, subject, status, error_message, created_at FROM email_event_logs";
if (!empty($emailWhere)) {
    $sql_email_events .= " WHERE " . implode(' AND ', $emailWhere);
}
$sql_email_events .= " ORDER BY created_at DESC LIMIT 50";
$res_email_events = $conn->query($sql_email_events);
$page_title = 'Notification History';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>✉️ Notification History</h1>
                <p>ประวัติการแจ้งเตือนในแอปและเหตุการณ์อีเมลที่ระบบพยายามส่ง</p>
            </div>
        </div>

        <div class="admin-filter-bar">
            <form method="get" action="notification_history.php" class="admin-filter-form">
                <div class="admin-form-group" style="flex:2;">
                    <label>ค้นหา</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="username / subject / email...">
                </div>
                <div class="admin-form-group">
                    <label>วันที่เริ่ม</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="admin-form-group">
                    <label>วันที่สิ้นสุด</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="admin-form-group">
                    <label>Email Status</label>
                    <select name="email_status">
                        <option value="">ทั้งหมด</option>
                        <option value="success" <?php echo $emailStatus === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="failed" <?php echo $emailStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div class="admin-form-group" style="flex:0;min-width:120px;">
                    <label>&nbsp;</label>
                    <button type="submit" class="admin-btn admin-btn-primary" style="width:100%;padding:10px;">กรอง</button>
                </div>
            </form>
        </div>

        <div class="admin-section">
            <h3 class="admin-section-title">🔔 In-App Notifications</h3>
            <div class="admin-panel">
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Message</th>
                                <th>Link</th>
                                <th>Read</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($res_notifications && $res_notifications->num_rows > 0): ?>
                            <?php while ($row = $res_notifications->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['message']); ?></td>
                                    <td class="admin-text-muted"><?php echo htmlspecialchars($row['link_url']); ?></td>
                                    <td><?php echo $row['is_read'] ? 'ใช่' : 'ไม่'; ?></td>
                                    <td class="admin-text-muted"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty">ไม่มีการแจ้งเตือนภายในระบบ</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="admin-section">
            <h3 class="admin-section-title">📧 Email Event Logs</h3>
            <div class="admin-panel">
                <div class="admin-panel-body">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Recipient</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Error</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($res_email_events && $res_email_events->num_rows > 0): ?>
                            <?php while ($row = $res_email_events->fetch_assoc()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['user_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['recipient_email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['subject']); ?></td>
                                    <td>
                                        <span class="admin-badge <?php echo $row['status'] === 'success' ? 'admin-badge-done' : 'admin-badge-rejected'; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="admin-text-muted"><?php echo htmlspecialchars($row['error_message']); ?></td>
                                    <td class="admin-text-muted"><?php echo $row['created_at']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="empty">ไม่มีประวัติอีเมล</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
