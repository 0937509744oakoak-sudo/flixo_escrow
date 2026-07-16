<?php

$current_page = basename($_SERVER['PHP_SELF']);

$is_logged_in = isset($_SESSION['user']);

$is_app_shell = !empty($use_app_shell);



$support_unread = 0;

if ($is_logged_in && isset($conn) && isset($_SESSION['user'])) {

    require_once __DIR__ . '/support_chat.php';

    $support_unread = getSupportUnreadCount($conn, $_SESSION['user']);

}

?>

<?php if ($is_logged_in): ?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/notifications.css?v=<?php echo time(); ?>">
<?php endif; ?>

<header class="navbar <?php echo $is_logged_in ? 'navbar-app' : ''; ?>">

    <div class="nav-left">

        <div class="logo" style="display:flex;align-items:center;gap:6px;">

            <?php if ($is_app_shell): ?>

            <button type="button" data-app-tab="home" style="background:none;border:none;cursor:pointer;padding:0;display:flex;align-items:center;gap:6px;font:inherit;">

                <span style="color:var(--primary-color);font-size:24px;font-weight:bold;font-family:var(--font-heading,'Hanken Grotesk',sans-serif);">FLIXO</span>

                <span class="logo-badge">Secure</span>

            </button>

            <?php else: ?>

            <a href="<?php echo $is_logged_in ? BASE_URL . '/dashboard.php' : BASE_URL . '/index.php'; ?>">FLIXO</a>

            <?php endif; ?>

        </div>

        <?php if ($is_logged_in && $is_app_shell): ?>

        <nav class="nav-main-links">

            <button type="button" class="nav-tab-btn" data-app-tab="home">ประกาศขาย</button>

            <button type="button" class="nav-tab-btn" data-app-tab="orders">รายการสั่งซื้อ</button>

            <button type="button" class="nav-tab-btn nav-tab-btn-chat" data-app-tab="chat">

                ห้องคุยคู่สัญญา

                <?php if ($support_unread > 0): ?>

                    <span class="nav-tab-badge"><?php echo (int) $support_unread; ?></span>

                <?php elseif (isset($chat_count) && $chat_count > 0): ?>

                    <span class="nav-dot"></span>

                <?php endif; ?>

            </button>

        </nav>

        <?php elseif ($is_logged_in && !$is_app_shell): ?>

        <nav class="nav-main-links">

            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="nav-main-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">ประกาศขาย</a>

            <a href="<?php echo BASE_URL; ?>/dashboard.php?tab=orders" class="nav-main-link">รายการสั่งซื้อ</a>

            <a href="<?php echo BASE_URL; ?>/dashboard.php?tab=chat" class="nav-main-link <?php echo ($current_page === 'dashboard.php' && ($_GET['tab'] ?? '') === 'chat') ? 'active' : ''; ?>">ห้องคุยคู่สัญญา</a>

        </nav>

        <?php endif; ?>

    </div>



    <?php if ($is_logged_in): ?>

        <div class="nav-right">

        <?php
        require_once __DIR__ . '/Rbac.php';
        $nav_staff_role = $_SESSION['role'] ?? '';
        if (Rbac::isStaff($nav_staff_role)):
        ?>
        <a href="<?php echo BASE_URL; ?>/admin/index.php" class="nav-admin-btn" title="ระบบ Admin — เฉพาะเจ้าหน้าที่">
            <span class="nav-admin-btn__icon" aria-hidden="true">🛡️</span>
            <span class="nav-admin-btn__text">Admin</span>
            <span class="nav-admin-btn__badge">Staff</span>
        </a>
        <?php endif; ?>

        <?php include __DIR__ . '/partials/notification_panel.php'; ?>

        <?php if ($is_app_shell): ?>

        <button type="button" class="nav-profile-btn" data-app-tab="profile" title="โปรไฟล์" style="border:none;cursor:pointer;">

            <span class="nav-profile-avatar"><?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?></span>

        </button>

        <?php else: ?>

        <a href="<?php echo BASE_URL; ?>/dashboard.php?tab=profile" class="nav-profile-btn" title="โปรไฟล์">

            <span class="nav-profile-avatar"><?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?></span>

        </a>

        <?php endif; ?>

    </div>

    <script src="<?php echo ASSETS_URL; ?>/js/notifications.js?v=<?php echo time(); ?>" defer></script>

    <?php else: ?>

    <div class="nav-links">

        <?php if ($current_page !== 'login.php' && $current_page !== 'register.php'): ?>

            <a href="<?php echo BASE_URL; ?>/register.php" class="nav-item" style="margin-right:8px;">สมัครฟรี</a>
            <a href="<?php echo BASE_URL; ?>/login.php" class="nav-item nav-login-btn">เข้าสู่ระบบ</a>

        <?php endif; ?>

    </div>

    <?php endif; ?>

</header>

