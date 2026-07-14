<?php
require_once __DIR__ . '/admin_helpers.php';

$admin_page = adminCurrentPage();
$badges = adminGetBadgeCounts($conn);
$admin_role = $_SESSION['role'] ?? 'admin';
$admin_user = $_SESSION['user'] ?? '';

function adminNavLink(string $file, string $label, int $badge = 0, string $badgeClass = ''): void
{
    global $admin_page;
    $activeClass = ($admin_page === $file) ? 'active' : '';
    $badgeHtml = '';
    if ($badge > 0) {
        $cls = 'admin-nav-badge' . ($badgeClass ? ' ' . $badgeClass : '');
        $badgeHtml = '<span class="' . $cls . '">' . $badge . '</span>';
    }
    echo '<li><a href="' . htmlspecialchars($file) . '" class="' . $activeClass . '">'
        . '<span>' . $label . '</span>' . $badgeHtml . '</a></li>';
}
?>

<div class="admin-sidebar">
    <div class="admin-sidebar-header">
        <h3>🛡️ FLIXO Admin</h3>
        <small>ระบบจัดการหลังบ้าน</small>
    </div>
    <div class="admin-sidebar-user">
        <strong><?php echo safeText($admin_user); ?></strong>
        บทบาท: <?php echo safeText($admin_role); ?>
    </div>
    <ul class="admin-nav">
        <?php adminNavLink('index.php', '📊 ภาพรวม (Dashboard)'); ?>
        <?php adminNavLink('escrow_list.php', '💰 จัดการ Escrow', $badges['waiting_verify'], 'warn'); ?>
        <?php adminNavLink('withdrawals_list.php', '💸 คำขอถอนเงิน', $badges['pending_withdrawals'], 'warn'); ?>
        <?php adminNavLink('users_list.php', '👥 จัดการสมาชิก'); ?>
        <?php adminNavLink('support_inbox.php', '💬 แชทช่วยเหลือ', $badges['support_unread']); ?>
        <?php adminNavLink('ekyc_logs.php', '🪪 eKYC Logs'); ?>
        <?php adminNavLink('product_fraud_queue.php', '🖼️ ตรวจรูปสินค้า (AI)', $badges['fraud_queue'], 'warn'); ?>
        <?php adminNavLink('chat_moderation_queue.php', '🔍 ตรวจแชท (AI)', $badges['chat_moderation_queue'], 'warn'); ?>
        <?php adminNavLink('dispute_list.php', '⚠️ จัดการข้อพิพาท', $badges['open_disputes']); ?>
        <?php adminNavLink('logs.php', '📋 ดู Logs'); ?>
        <?php adminNavLink('pii_audit_log.php', '🔒 PII Audit Log'); ?>
        <?php adminNavLink('notification_history.php', '✉️ Notification History'); ?>
        <li class="admin-nav-divider"></li>
        <li><a href="../dashboard.php" class="nav-home">🏠 กลับหน้าบ้าน</a></li>
    </ul>
</div>
