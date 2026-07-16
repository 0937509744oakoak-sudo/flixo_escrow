<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/OrderStateMachine.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/Review.php';
require_once __DIR__ . '/includes/chat_helpers.php';

$use_app_shell = true;
$initial_tab = $_GET['tab'] ?? 'home';
$allowed_tabs = ['home', 'orders', 'chat', 'profile', 'sell', 'my_products'];
if (!in_array($initial_tab, $allowed_tabs, true)) {
    $initial_tab = 'home';
}

$chat_partner = trim($_GET['user'] ?? '');

$page_title = 'Dashboard';
include 'includes/header.php';
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/chat-page.css?v=<?php echo time(); ?>">
<?php

$userModel = new User($conn);
$orderModel = new Order($conn);
$productModel = new Product($conn);
$reviewModel = new Review($conn);

$orderModel->cancelExpiredPendingOrders(24);

$user = $userModel->findByUsername($me);
$my_display_name = User::resolveDisplayName($user['real_name'] ?? '', $me);
$seller_verified = $userModel->isVerified($me);
$chat_count = $userModel->getChatCount($me);
$sidebar_partners = flixo_build_chat_sidebar($userModel, $me);
$sidebar_partners = flixo_finalize_chat_sidebar($conn, $me, $sidebar_partners);
$chat_display_names = [];
foreach ($sidebar_partners as $username => $info) {
    $chat_display_names[$username] = $info['display_name'] ?? $username;
}
if ($chat_partner !== '') {
    $chat_display_names[$chat_partner] = $userModel->getDisplayName($chat_partner);
}
require_once __DIR__ . '/includes/support_chat.php';
$support_username = getSupportUsername();
$stats = $orderModel->getDashboardStats($me);
$earnings = $orderModel->getEarningsStats($me);
$recent_orders = $orderModel->getRecentOrders($me, 5);

$todo_count = $stats['todo_count'];
$money_pending = $stats['money_pending'];
$withdrawable = $earnings['total'] ?? 0;

$search_product = trim($_GET['search_product'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$catalog_sort = $_GET['sort'] ?? 'newest';
$allowed_sorts = ['newest', 'price_asc', 'price_desc'];
if (!in_array($catalog_sort, $allowed_sorts, true)) {
    $catalog_sort = 'newest';
}
$catalog_per_page = 24;
$catalog_page = max(1, (int) ($_GET['page'] ?? 1));
$catalog_offset = ($catalog_page - 1) * $catalog_per_page;

$catalog_total = $productModel->countFiltered($category_filter, $search_product);
$catalog_products = $productModel->getFiltered($catalog_per_page, $catalog_offset, $category_filter, $catalog_sort, $search_product);

$catalog_total_pages = max(1, (int) ceil($catalog_total / $catalog_per_page));
if ($catalog_page > $catalog_total_pages) {
    $catalog_page = $catalog_total_pages;
    $catalog_offset = ($catalog_page - 1) * $catalog_per_page;
    $catalog_products = $productModel->getFiltered($catalog_per_page, $catalog_offset, $category_filter, $catalog_sort, $search_product);
}

$profile_reviews = $reviewModel->getByTarget($me, 10);

$thai_months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

function formatThaiDate($datetime, array $thai_months) {
    if (empty($datetime)) {
        return '';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '';
    }
    $m = (int) date('n', $ts);
    return date('j', $ts) . ' ' . $thai_months[$m] . ' ' . date('Y', $ts) . ' • ' . date('H:i', $ts);
}

function orderStatusMeta($status) {
    switch ($status) {
        case OrderStateMachine::COMPLETED:
            return ['label' => 'สำเร็จ', 'class' => 'status-success'];
        case OrderStateMachine::PAID:
        case OrderStateMachine::SHIPPED:
        case OrderStateMachine::WAITING_VERIFY:
            return ['label' => 'กำลังดำเนินการ', 'class' => 'status-progress'];
        case OrderStateMachine::PENDING_PAYMENT:
            return ['label' => 'รอชำระเงิน', 'class' => 'status-pending'];
        case OrderStateMachine::DISPUTE:
            return ['label' => 'ข้อพิพาท', 'class' => 'status-dispute'];
        case OrderStateMachine::CANCELLED:
            return ['label' => 'ยกเลิก', 'class' => 'status-cancelled'];
        default:
            return ['label' => $status, 'class' => 'status-progress'];
    }
}

include 'includes/navbar.php';
?>

<div id="flixo-app" class="flixo-app" data-initial-tab="<?php echo htmlspecialchars($initial_tab); ?>">

    <div id="home-chrome" class="home-chrome<?php echo $initial_tab === 'home' ? ' is-visible' : ''; ?>">
        <section class="hero-gradient-finance hero-compact">
            <div class="hero-inner">
                <div class="hero-greeting">
                    <h1>สวัสดี, <?php echo htmlspecialchars($my_display_name); ?></h1>
                    <p class="hero-subtitle">ซื้อขายปลอดภัยด้วยระบบ FLIXO Escrow</p>
                </div>
                <div class="hero-search-wrap">
                    <form id="hero-search-form" class="hero-search-box-finance" method="GET" action="dashboard.php">
                        <div class="hero-search-input-row">
                            <span class="material-symbols-outlined">search</span>
                            <input type="text" name="search_product" placeholder="ค้นหาสินค้าหรือผู้ขาย..." value="<?php echo htmlspecialchars($search_product); ?>">
                        </div>
                        <button type="submit" class="hero-search-btn-finance">
                            <span class="material-symbols-outlined">search</span>
                            <span>ค้นหา</span>
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <div class="home-quick-strip">
            <button type="button" class="home-quick-btn" data-quick-tab="sell">
                <span class="material-symbols-outlined">add_shopping_cart</span>
                <span>ลงประกาศ</span>
            </button>
            <button type="button" class="home-quick-btn" data-quick-tab="my_products">
                <span class="material-symbols-outlined">inventory_2</span>
                <span>ประกาศของฉัน</span>
            </button>
        </div>
    </div>

    <main id="flixo-main" class="flixo-main<?php
        echo $initial_tab === 'home' ? ' flixo-main--overlap' : '';
        echo $initial_tab === 'chat' ? ' flixo-main--chat' : '';
        echo ($initial_tab !== 'home' && $initial_tab !== 'chat') ? ' flixo-main--tab' : '';
    ?>">

        <div id="app-views">
            <div id="view-home" class="app-view" data-tab="home">
                <?php include __DIR__ . '/includes/views/view_home.php'; ?>
            </div>
            <div id="view-orders" class="app-view" data-tab="orders">
                <?php include __DIR__ . '/includes/views/view_orders.php'; ?>
            </div>
            <div id="view-chat" class="app-view app-view--chat" data-tab="chat">
                <?php include __DIR__ . '/includes/views/view_chat.php'; ?>
            </div>
            <div id="view-profile" class="app-view" data-tab="profile">
                <?php include __DIR__ . '/includes/views/view_profile.php'; ?>
            </div>
            <div id="view-sell-product" class="app-view" data-tab="sell">
                <?php include __DIR__ . '/includes/views/view_sell_product.php'; ?>
            </div>
            <div id="view-my-products" class="app-view" data-tab="my_products">
                <?php include __DIR__ . '/includes/views/view_my_products.php'; ?>
            </div>
        </div>

        <?php
        require_once __DIR__ . '/includes/Rbac.php';
        if (isset($user['role']) && Rbac::isStaff($user['role'])):
            $staff_role = $user['role'];
            $staff_role_label = Rbac::roleLabel($staff_role);
        ?>
        <div class="staff-admin-portal">
            <div class="staff-admin-portal__inner">
                <div class="staff-admin-portal__icon" aria-hidden="true">🛡️</div>
                <div class="staff-admin-portal__content">
                    <span class="staff-admin-portal__badge">🔐 เฉพาะเจ้าหน้าที่ — ไม่ใช่ผู้ใช้ทั่วไป</span>
                    <strong class="staff-admin-portal__title">ระบบจัดการหลังบ้าน (Admin Panel)</strong>
                    <p class="staff-admin-portal__desc">
                        บัญชีของคุณมีสิทธิ์ <strong><?php echo safeText($staff_role_label); ?></strong>
                        — เข้าถึงได้เฉพาะเจ้าหน้าที่ (Owner, Finance, Support, Moderator) เท่านั้น
                    </p>
                </div>
                <a href="admin/index.php" class="staff-admin-portal__btn">
                    เข้าสู่ Admin Dashboard
                    <span class="staff-admin-portal__btn-arrow">→</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<?php include __DIR__ . '/includes/partials/chat_modals.php'; ?>

<?php include 'includes/app_mobile_nav.php'; ?>

<script>
window.FLIXO_BASE_URL = <?php echo json_encode(rtrim(BASE_URL, '/')); ?>;
window.FLIXO_DISPLAY_NAMES = <?php echo json_encode($chat_display_names, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo ASSETS_URL; ?>/js/chat.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo ASSETS_URL; ?>/js/dispute-open.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo ASSETS_URL; ?>/js/app-shell.js?v=<?php echo time(); ?>"></script>

<?php include 'includes/footer.php'; ?>
