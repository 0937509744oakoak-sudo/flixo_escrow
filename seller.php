<?php
/**
 * Public seller profile — community hub (followers, reviews, presence, listings).
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Review.php';

$is_logged_in = !empty($_SESSION['user']);
$me = $is_logged_in ? $_SESSION['user'] : '';

$seller_username = sanitizeUsername($_GET['u'] ?? '');
if ($seller_username === '') {
    redirectTo($is_logged_in ? 'dashboard.php' : 'index.php');
}

$userModel = new User($conn);
$seller = $userModel->findByUsername($seller_username);
if (!$seller || ($seller['status'] ?? '') === 'banned') {
    redirectTo($is_logged_in ? 'dashboard.php' : 'index.php');
}

$productModel = new Product($conn);
$reviewModel = new Review($conn);

$seller_display = User::resolveDisplayName($seller['real_name'] ?? '', $seller_username);
$is_own_profile = $is_logged_in && $me === $seller_username;
$is_verified = !empty($seller['is_verified']);
$is_online = flixoIsOnline($seller['last_active_at'] ?? null);
$last_active_label = flixoTimeAgo($seller['last_active_at'] ?? null);

$profile_stats = $userModel->getSellerProfileStats($seller_username);
$review_stats = $userModel->getReviewStats($seller_username);
$review_total = (int) ($review_stats['total_reviews'] ?? 0);
$avg_score = (float) ($review_stats['avg_score'] ?? 0);
$star_count = (int) round(min(5, max(0, $avg_score)));
$follower_count = $userModel->countFollowers($seller_username);
$is_following = $is_logged_in && !$is_own_profile && $userModel->isFollowing($me, $seller_username);
$reviews = $reviewModel->getByTarget($seller_username, 10);

$response_label = null;
if ($profile_stats['avg_response_minutes'] !== null) {
    $mins = $profile_stats['avg_response_minutes'];
    if ($mins < 1) {
        $response_label = 'ไม่ถึง 1 นาที';
    } elseif ($mins < 60) {
        $response_label = number_format($mins) . ' นาที';
    } elseif ($mins < 60 * 24) {
        $response_label = number_format($mins / 60, 1) . ' ชม.';
    } else {
        $response_label = number_format($mins / (60 * 24), 1) . ' วัน';
    }
}

// ประกาศขายที่แสดงต่อสาธารณะ
$listings = [];
$listingResult = $productModel->getBySeller($seller_username, 24);
if ($listingResult) {
    while ($p = $listingResult->fetch_assoc()) {
        if ($productModel->isPubliclyVisible($p)) {
            $listings[] = $p;
        }
    }
}

$login_redirect = 'login.php?redirect=' . urlencode('seller.php?u=' . rawurlencode($seller_username));
$page_title = 'ร้านของ ' . $seller_display;
$load_flixo_css = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>

<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/landing.css?v=<?php echo time(); ?>">

<main class="seller-profile-page">
    <div class="seller-profile-inner">

        <section class="seller-hero flixo-card">
            <div class="seller-hero-main">
                <div class="seller-hero-avatar-wrap">
                    <div class="seller-hero-avatar"><?php echo strtoupper(mb_substr($seller_display, 0, 1)); ?></div>
                    <?php if ($is_online): ?><span class="seller-online-dot" title="ออนไลน์อยู่"></span><?php endif; ?>
                </div>
                <div class="seller-hero-info">
                    <h1 class="seller-hero-name">
                        <?php echo htmlspecialchars($seller_display); ?>
                        <?php if ($is_verified): ?>
                            <span class="product-seller-verified" title="ยืนยันตัวตนแล้ว">
                                <span class="material-symbols-outlined" aria-hidden="true">verified</span> ยืนยันตัวตนแล้ว
                            </span>
                        <?php endif; ?>
                    </h1>
                    <p class="seller-hero-presence">
                        <?php if ($is_online): ?>
                            <span class="seller-presence-online">● ออนไลน์อยู่ตอนนี้</span>
                        <?php elseif ($last_active_label !== ''): ?>
                            ใช้งานล่าสุด <?php echo htmlspecialchars($last_active_label); ?>
                        <?php else: ?>
                            ยังไม่เคยเข้าใช้งาน
                        <?php endif; ?>
                        <?php if (!empty($seller['created_at'])): ?>
                            · เป็นสมาชิกตั้งแต่ปี <?php echo date('Y', strtotime($seller['created_at'])); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($review_total > 0): ?>
                        <div class="product-seller-stars">
                            <span class="product-seller-stars-icons"><?php echo str_repeat('★', $star_count) . str_repeat('☆', 5 - $star_count); ?></span>
                            <span class="product-seller-stars-meta"><?php echo number_format($avg_score, 1); ?> (<?php echo number_format($review_total); ?> รีวิว)</span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="seller-hero-actions">
                    <?php if ($is_own_profile): ?>
                        <a href="dashboard.php?tab=profile" class="btn-flixo-outline seller-action-btn">แก้ไขโปรไฟล์</a>
                    <?php elseif ($is_logged_in): ?>
                        <button type="button" id="follow-btn" class="<?php echo $is_following ? 'btn-flixo-outline' : 'btn-flixo-primary'; ?> seller-action-btn"
                                data-target="<?php echo htmlspecialchars($seller_username); ?>" data-following="<?php echo $is_following ? '1' : '0'; ?>">
                            <?php echo $is_following ? '✓ ติดตามแล้ว' : '+ ติดตาม'; ?>
                        </button>
                        <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($seller_username); ?>" class="btn-flixo-outline seller-action-btn">คุยกับผู้ขาย</a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($login_redirect); ?>" class="btn-flixo-primary seller-action-btn">เข้าสู่ระบบเพื่อติดตาม</a>
                    <?php endif; ?>
                </div>
            </div>

            <ul class="seller-stats-strip">
                <li>
                    <span class="seller-stat-value" id="follower-count"><?php echo number_format($follower_count); ?></span>
                    <span class="seller-stat-label">ผู้ติดตาม</span>
                </li>
                <li>
                    <span class="seller-stat-value"><?php echo number_format($profile_stats['sold_count']); ?></span>
                    <span class="seller-stat-label">ขายสำเร็จ</span>
                </li>
                <li>
                    <span class="seller-stat-value"><?php echo $profile_stats['response_rate'] !== null ? number_format($profile_stats['response_rate']) . '%' : '—'; ?></span>
                    <span class="seller-stat-label">อัตราตอบแชท (24 ชม.)</span>
                </li>
                <li>
                    <span class="seller-stat-value"><?php echo $response_label !== null ? htmlspecialchars($response_label) : '—'; ?></span>
                    <span class="seller-stat-label">ตอบแชทเฉลี่ย</span>
                </li>
                <li>
                    <span class="seller-stat-value"><?php echo number_format(count($listings)); ?></span>
                    <span class="seller-stat-label">ประกาศที่เปิดขาย</span>
                </li>
            </ul>
        </section>

        <section class="seller-listings-section">
            <h2 class="landing-section-title">ประกาศขายของ <?php echo htmlspecialchars($seller_display); ?></h2>
            <?php if ($listings !== []): ?>
                <div class="product-grid">
                    <?php foreach ($listings as $p): ?>
                    <a href="product.php?id=<?php echo (int)$p['id']; ?>" class="product-card">
                        <div class="product-card-img">
                            <img src="<?php echo htmlspecialchars(Product::imageUrl($p['image_path'])); ?>" alt="<?php echo htmlspecialchars($p['product_name']); ?>" loading="lazy" onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                        </div>
                        <div class="product-card-body">
                            <div class="product-card-name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                            <div class="product-card-price">฿<?php echo number_format($p['price'], 0); ?></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-box">
                    <span class="material-symbols-outlined">storefront</span>
                    <p>ยังไม่มีประกาศที่เปิดขายตอนนี้</p>
                </div>
            <?php endif; ?>
        </section>

        <section class="product-reviews-section flixo-card">
            <div class="card-header-flex">
                <div>
                    <h3>รีวิวจากผู้ซื้อ</h3>
                    <?php if ($review_total > 0): ?>
                    <p class="tx-meta" style="margin:4px 0 0;">
                        ★ <?php echo number_format($avg_score, 1); ?> · <?php echo number_format($review_total); ?> รีวิว
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            $empty_message = 'ยังไม่มีรีวิว — เป็นคนแรกที่ซื้อและรีวิวผู้ขายรายนี้';
            include __DIR__ . '/includes/partials/review_list.php';
            ?>
        </section>
    </div>
</main>

<?php if ($is_logged_in && !$is_own_profile): ?>
<script>
document.getElementById('follow-btn')?.addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;

    const fd = new FormData();
    fd.append('target', btn.dataset.target);

    fetch('<?php echo BASE_URL; ?>/controllers/FollowController.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            if (data.status !== 'success') {
                Swal.fire('ไม่สำเร็จ', data.message || 'เกิดข้อผิดพลาด', 'error');
                return;
            }
            btn.dataset.following = data.following ? '1' : '0';
            btn.textContent = data.following ? '✓ ติดตามแล้ว' : '+ ติดตาม';
            btn.classList.toggle('btn-flixo-primary', !data.following);
            btn.classList.toggle('btn-flixo-outline', data.following);
            document.getElementById('follower-count').textContent = Number(data.follower_count).toLocaleString();
        })
        .catch(() => {
            btn.disabled = false;
            Swal.fire('ไม่สำเร็จ', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error');
        });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
