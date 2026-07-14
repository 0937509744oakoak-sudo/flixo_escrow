<?php
require 'admin_check.php';

$q = trim($_GET['q'] ?? '');
$where = '';
if ($q !== '') {
    $qEsc = $conn->real_escape_string(mb_substr($q, 0, 100));
    $where = "WHERE admin_username LIKE '%$qEsc%' OR target_username LIKE '%$qEsc%' OR action LIKE '%$qEsc%'";
}

$sql = "SELECT id, admin_username, target_username, action, resource_type, resource_id, ip_address, created_at
        FROM pii_audit_logs $where ORDER BY created_at DESC LIMIT 100";
$result = $conn->query($sql);
$page_title = 'PII Audit Log';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>🔒 PII Access Audit Log</h1>
                <p>บันทึกทุกครั้งที่แอดมินเข้าถึงข้อมูลส่วนบุคคล (เช่น รูปบัตรประชาชน KYC)</p>
            </div>
        </div>

        <div class="admin-filter-bar">
            <form method="get" class="admin-filter-form">
                <div class="admin-form-group" style="flex:2;">
                    <label>ค้นหา</label>
                    <input type="text" name="q" value="<?php echo safeText($q); ?>" placeholder="admin / user / action">
                </div>
                <div class="admin-form-group" style="flex:0;min-width:120px;">
                    <label>&nbsp;</label>
                    <button type="submit" class="admin-btn admin-btn-primary" style="width:100%;padding:10px;">ค้นหา</button>
                </div>
            </form>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>เวลา</th>
                            <th>Admin</th>
                            <th>ผู้ใช้เป้าหมาย</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="admin-text-muted"><?php echo safeText($row['created_at']); ?></td>
                            <td><?php echo safeText($row['admin_username']); ?></td>
                            <td><?php echo safeText($row['target_username']); ?></td>
                            <td><?php echo safeText($row['action']); ?></td>
                            <td><?php echo safeText($row['resource_type'] . ($row['resource_id'] ? ': ' . $row['resource_id'] : '')); ?></td>
                            <td class="admin-text-muted"><?php echo safeText($row['ip_address']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty">ยังไม่มีบันทึก</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
