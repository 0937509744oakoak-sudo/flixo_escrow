<?php
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/../includes/Rbac.php';

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

function adminNavSection(string $label): void
{
    echo '<li class="admin-nav-section">' . htmlspecialchars($label) . '</li>';
}

$canSee = static fn(string $perm): bool => Rbac::can($GLOBALS['admin_role'], $perm);
?>

<div class="admin-sidebar">
    <div class="admin-sidebar-header">
        <h3>🛡️ FLIXO Admin</h3>
        <small>ระบบจัดการหลังบ้าน</small>
    </div>
    <div class="admin-sidebar-user">
        <strong><?php echo safeText($admin_user); ?></strong>
        บทบาท: <?php echo safeText(Rbac::roleLabel($admin_role)); ?>
    </div>
    <ul class="admin-nav">
        <?php adminNavLink('index.php', '📊 ภาพรวม (Dashboard)'); ?>

        <?php if ($canSee('escrow.manage') || $canSee('withdrawal.manage')): ?>
            <?php adminNavSection('Finance — การเงิน'); ?>
            <?php if ($canSee('escrow.manage')) adminNavLink('escrow_list.php', '💰 จัดการ Escrow', $badges['waiting_verify'], 'warn'); ?>
            <?php if ($canSee('withdrawal.manage')) adminNavLink('withdrawals_list.php', '💸 คำขอถอนเงิน', $badges['pending_withdrawals'], 'warn'); ?>
        <?php endif; ?>

        <?php if ($canSee('support.inbox') || $canSee('dispute.resolve')): ?>
            <?php adminNavSection('Support — ซัพพอร์ต'); ?>
            <?php if ($canSee('support.inbox')) adminNavLink('support_inbox.php', '💬 แชทช่วยเหลือ', $badges['support_unread']); ?>
            <?php if ($canSee('dispute.resolve')) adminNavLink('dispute_list.php', '⚠️ จัดการข้อพิพาท', $badges['open_disputes']); ?>
        <?php endif; ?>

        <?php if ($canSee('users.manage') || $canSee('products.manage') || $canSee('moderation.queue') || $canSee('kyc.review')): ?>
            <?php adminNavSection('Moderator — ตรวจสอบ'); ?>
            <?php if ($canSee('users.manage')) adminNavLink('users_list.php', '👥 จัดการสมาชิก'); ?>
            <?php if ($canSee('kyc.review')) adminNavLink('ekyc_logs.php', '🪪 eKYC Logs'); ?>
            <?php if ($canSee('products.manage')) adminNavLink('products_list.php', '📦 จัดการสินค้า'); ?>
            <?php if ($canSee('moderation.queue')) adminNavLink('product_fraud_queue.php', '🖼️ ตรวจรูปสินค้า (AI)', $badges['fraud_queue'], 'warn'); ?>
            <?php if ($canSee('moderation.queue')) adminNavLink('chat_moderation_queue.php', '🔍 ตรวจแชท (AI)', $badges['chat_moderation_queue'], 'warn'); ?>
        <?php endif; ?>

        <?php if ($canSee('logs.view') || $canSee('audit.view') || $canSee('notifications.view')): ?>
            <?php adminNavSection('Owner — ระบบ'); ?>
            <?php if ($canSee('logs.view')) adminNavLink('logs.php', '📋 ดู Logs'); ?>
            <?php if ($canSee('audit.view')) adminNavLink('pii_audit_log.php', '🔒 PII Audit Log'); ?>
            <?php if ($canSee('notifications.view')) adminNavLink('notification_history.php', '✉️ Notification History'); ?>
        <?php endif; ?>

        <li class="admin-nav-divider"></li>
        <li><a href="../dashboard.php" class="nav-home">🏠 กลับหน้าบ้าน</a></li>
    </ul>
</div>
