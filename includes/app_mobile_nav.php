<?php
/** Mobile bottom navigation for app shell — includes Sell CTA */
$has_chat_unread = isset($chat_count) && $chat_count > 0;
$mobile_support_unread = 0;
if (isset($conn) && isset($_SESSION['user'])) {
    require_once __DIR__ . '/support_chat.php';
    $mobile_support_unread = getSupportUnreadCount($conn, $_SESSION['user']);
}
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/landing.css?v=<?php echo time(); ?>">
<nav class="flixo-mobile-nav" aria-label="เมนูหลัก">
    <button type="button" class="mobile-nav-btn active" data-app-tab="home">
        <span class="material-symbols-outlined">home</span>
        <span>หน้าหลัก</span>
    </button>
    <button type="button" class="mobile-nav-btn" data-app-tab="orders">
        <span class="material-symbols-outlined">receipt_long</span>
        <span>ออเดอร์</span>
    </button>
    <button type="button" class="mobile-nav-btn mobile-nav-btn-sell" data-app-tab="sell" aria-label="ลงประกาศขาย">
        <span class="mobile-sell-circle">
            <span class="material-symbols-outlined">add</span>
        </span>
        <span>ลงขาย</span>
    </button>
    <button type="button" class="mobile-nav-btn nav-tab-btn-chat" data-app-tab="chat">
        <span class="material-symbols-outlined">chat</span>
        <span>แชท</span>
        <?php if ($mobile_support_unread > 0): ?>
            <span class="mobile-nav-badge"><?php echo (int) $mobile_support_unread; ?></span>
        <?php elseif ($has_chat_unread): ?>
            <span class="nav-dot" style="position:absolute;top:4px;right:8px;width:8px;height:8px;background:var(--flixo-error);border-radius:50%;"></span>
        <?php endif; ?>
    </button>
    <button type="button" class="mobile-nav-btn" data-app-tab="profile">
        <span class="material-symbols-outlined">person</span>
        <span>โปรไฟล์</span>
    </button>
</nav>
