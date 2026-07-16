<?php

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Review.php';

$is_logged_in = !empty($_SESSION['user']);
$me = $is_logged_in ? $_SESSION['user'] : '';

$productId = (int)($_GET['id'] ?? 0);
if ($productId <= 0) {
    redirectTo($is_logged_in ? 'dashboard.php' : 'index.php');
}

$productModel = new Product($conn);
$product = $productModel->getById($productId);
$reviewModel = new Review($conn);
$userModel = new User($conn);

if (!$product) {
    redirectTo($is_logged_in ? 'dashboard.php' : 'index.php');
}

$isOwner = $is_logged_in && $_SESSION['user'] === $product['seller_name'];
if (!$productModel->isPubliclyVisible($product) && !$isOwner) {
    redirectTo($is_logged_in ? 'dashboard.php' : 'index.php');
}

$page_title = $product['product_name'];
$load_flixo_css = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';

$images = $product['images'] ?? [];
if (empty($images) && !empty($product['image_path'])) {
    $images = [['file_path' => $product['image_path'], 'is_primary' => 1]];
}

$rating = $product['seller_rating'] ? number_format($product['seller_rating'], 1) : null;
$seller_display = $product['seller_display_name'] ?? userDisplayName($conn, $product['seller_name']);
$isOwnProduct = $is_logged_in && ($product['seller_name'] === $me);
$category = $product['category'] ?? '';
$seller_review_stats = $userModel->getReviewStats($product['seller_name']);
$seller_reviews = $reviewModel->getByTarget($product['seller_name'], 5);
$seller_review_total = (int) ($seller_review_stats['total_reviews'] ?? 0);
$seller_profile_stats = $userModel->getSellerProfileStats($product['seller_name']);
$seller_row = $userModel->findByUsername($product['seller_name']);
$seller_is_online = flixoIsOnline($seller_row['last_active_at'] ?? null);
$seller_last_active = flixoTimeAgo($seller_row['last_active_at'] ?? null);
$seller_profile_url = 'seller.php?u=' . rawurlencode($product['seller_name']);
$seller_avg_score = (float) ($seller_review_stats['avg_score'] ?? 0);
$seller_star_count = (int) round(min(5, max(0, $seller_avg_score)));

$seller_response_label = null;
if ($seller_profile_stats['avg_response_minutes'] !== null) {
    $mins = $seller_profile_stats['avg_response_minutes'];
    if ($mins < 1) {
        $seller_response_label = 'ไม่ถึง 1 นาที';
    } elseif ($mins < 60) {
        $seller_response_label = number_format($mins) . ' นาที';
    } elseif ($mins < 60 * 24) {
        $seller_response_label = number_format($mins / 60, 1) . ' ชม.';
    } else {
        $seller_response_label = number_format($mins / (60 * 24), 1) . ' วัน';
    }
}
$back_href = $is_logged_in ? 'dashboard.php' : 'index.php#catalog';
$login_redirect = 'login.php?redirect=' . urlencode('product.php?id=' . $productId);
?>

<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/landing.css?v=<?php echo time(); ?>">

<main class="product-detail-page">
    <div class="product-detail-inner">
        <a href="<?php echo htmlspecialchars($back_href); ?>" class="product-back-link">
            <span class="material-symbols-outlined">arrow_back</span> กลับหน้าหลัก
        </a>

        <div class="product-detail-grid">
            <div class="product-detail-gallery-wrap">
                <div class="product-detail-gallery" id="product-main-gallery">
                    <?php if (!empty($images)): ?>
                        <img id="product-main-image" src="<?php echo htmlspecialchars(Product::imageUrl($images[0]['file_path'])); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>" onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                    <?php else: ?>
                        <img id="product-main-image" src="<?php echo htmlspecialchars(Product::imageUrl('')); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>">
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                <div class="product-thumb-strip" role="tablist" aria-label="รูปสินค้าทั้งหมด">
                    <?php foreach ($images as $index => $img): ?>
                    <button type="button"
                        class="product-thumb-btn<?php echo $index === 0 ? ' is-active' : ''; ?>"
                        data-src="<?php echo htmlspecialchars(Product::imageUrl($img['file_path'])); ?>"
                        aria-label="ดูรูปที่ <?php echo $index + 1; ?>"
                        aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                        <img src="<?php echo htmlspecialchars(Product::imageUrl($img['file_path'])); ?>" alt="" onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (count($images) > 0): ?>
                    <p class="product-gallery-meta"><?php echo count($images); ?> รูป</p>
                <?php endif; ?>
            </div>

            <div class="product-detail-info">
                <?php if ($category): ?>
                    <span class="product-category-chip"><?php echo htmlspecialchars($category); ?></span>
                <?php endif; ?>
                <h1><?php echo htmlspecialchars($product['product_name']); ?></h1>
                <div class="product-detail-price">฿<?php echo number_format($product['price'], 2); ?></div>

                <div class="product-trust-row">
                    <span class="product-trust-pill">
                        <span class="material-symbols-outlined" aria-hidden="true" style="font-size:14px;">lock</span>
                        เงินพักกลางจนกว่าของถึงมือ
                    </span>
                </div>

                <div class="product-detail-seller product-seller-card">
                    <div class="product-seller-head">
                        <a href="<?php echo htmlspecialchars($seller_profile_url); ?>" class="product-seller-avatar-link" aria-label="ดูโปรไฟล์ผู้ขาย">
                            <div class="product-seller-avatar"><?php echo strtoupper(mb_substr($seller_display, 0, 1)); ?></div>
                            <?php if ($seller_is_online): ?><span class="product-seller-online-dot" title="ออนไลน์อยู่"></span><?php endif; ?>
                        </a>
                        <div class="product-seller-identity">
                            <div class="product-seller-name">
                                <a href="<?php echo htmlspecialchars($seller_profile_url); ?>" class="product-seller-name-link"><?php echo htmlspecialchars($seller_display); ?></a>
                                <?php if (!empty($product['seller_verified'])): ?>
                                    <span class="product-seller-verified" title="ยืนยันตัวตนแล้ว">
                                        <span class="material-symbols-outlined" aria-hidden="true">verified</span> ยืนยันตัวตนแล้ว
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($seller_review_total > 0): ?>
                                <div class="product-seller-stars" aria-label="คะแนนผู้ขาย <?php echo number_format($seller_avg_score, 1); ?> จาก 5">
                                    <span class="product-seller-stars-icons"><?php
                                        echo str_repeat('★', $seller_star_count) . str_repeat('☆', 5 - $seller_star_count);
                                    ?></span>
                                    <span class="product-seller-stars-meta"><?php echo number_format($seller_avg_score, 1); ?> (<?php echo number_format($seller_review_total); ?> รีวิว)</span>
                                </div>
                            <?php else: ?>
                                <div class="product-seller-stars product-seller-stars-empty">ยังไม่มีรีวิว</div>
                            <?php endif; ?>
                            <div class="product-seller-presence">
                                <?php if ($seller_is_online): ?>
                                    <span class="seller-presence-online">● ออนไลน์อยู่ตอนนี้</span>
                                <?php elseif ($seller_last_active !== ''): ?>
                                    ใช้งานล่าสุด <?php echo htmlspecialchars($seller_last_active); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$isOwnProduct): ?>
                            <?php if ($is_logged_in): ?>
                                <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($product['seller_name']); ?>" class="btn-flixo-outline product-seller-chat-btn">คุยกับผู้ขาย</a>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars($login_redirect); ?>" class="btn-flixo-outline product-seller-chat-btn">เข้าสู่ระบบเพื่อคุย</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <ul class="product-seller-stats">
                        <li>
                            <span class="product-seller-stat-value"><?php echo number_format($seller_profile_stats['sold_count']); ?></span>
                            <span class="product-seller-stat-label">ขายสำเร็จ (รายการ)</span>
                        </li>
                        <li>
                            <span class="product-seller-stat-value"><?php echo $seller_response_label !== null ? htmlspecialchars($seller_response_label) : '—'; ?></span>
                            <span class="product-seller-stat-label">ตอบแชทเฉลี่ย</span>
                        </li>
                        <li>
                            <span class="product-seller-stat-value"><?php echo $seller_profile_stats['joined_year'] ? 'ปี ' . $seller_profile_stats['joined_year'] : '—'; ?></span>
                            <span class="product-seller-stat-label">เป็นสมาชิกตั้งแต่</span>
                        </li>
                    </ul>
                </div>

                <?php if (!empty($product['description'])): ?>
                <div class="product-detail-desc">
                    <h3>รายละเอียดสินค้า</h3>
                    <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                </div>
                <?php endif; ?>

                <div class="product-escrow-badge">
                    <span class="material-symbols-outlined">verified_user</span>
                    <div>
                        <strong>เงินพักไว้กับ FLIXO</strong>
                        <p>ผู้ขายได้รับเงินเมื่อคุณยืนยันว่ารับสินค้าครบแล้วเท่านั้น</p>
                    </div>
                </div>

                <?php if ($isOwnProduct): ?>
                    <button type="button" class="btn-flixo-outline product-buy-btn" disabled>นี่คือสินค้าของคุณ</button>
                <?php elseif ($is_logged_in): ?>
                    <button type="button" id="buy-product-btn" class="btn-flixo-primary product-buy-btn" data-product-id="<?php echo $productId; ?>">
                        ซื้อเลย — เงินพักกลางปลอดภัย
                    </button>
                <?php else: ?>
                    <a href="<?php echo htmlspecialchars($login_redirect); ?>" class="btn-flixo-primary product-buy-btn" style="text-align:center;text-decoration:none;">
                        เข้าสู่ระบบเพื่อซื้อ
                    </a>
                    <p class="tx-meta" style="margin-top:8px;text-align:center;">ยังไม่มีบัญชี? <a href="register.php?redirect=<?php echo urlencode('product.php?id=' . $productId); ?>">สมัครฟรี</a></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($seller_review_total > 0): ?>
        <section class="product-reviews-section flixo-card">
            <div class="card-header-flex">
                <div>
                    <h3>รีวิวผู้ขาย</h3>
                    <p class="tx-meta" style="margin:4px 0 0;">
                        ★ <?php echo number_format($seller_review_stats['avg_score'] ?? 0, 1); ?>
                        · <?php echo number_format($seller_review_total); ?> รีวิว
                    </p>
                </div>
            </div>
            <?php
            $reviews = $seller_reviews;
            $empty_message = '';
            include __DIR__ . '/includes/partials/review_list.php';
            ?>
            <?php if ($seller_review_total > count($seller_reviews)): ?>
                <p class="tx-meta product-reviews-more">แสดง <?php echo count($seller_reviews); ?> รีวิวล่าสุดจากทั้งหมด <?php echo number_format($seller_review_total); ?> รีวิว</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</main>

<script>
document.querySelectorAll('.product-thumb-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const src = btn.getAttribute('data-src');
        const main = document.getElementById('product-main-image');
        if (main && src) main.src = src;
        document.querySelectorAll('.product-thumb-btn').forEach(function (b) {
            b.classList.remove('is-active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
    });
});

document.getElementById('buy-product-btn')?.addEventListener('click', function () {
    const btn = this;
    const productId = btn.dataset.productId;
    btn.disabled = true;
    btn.textContent = 'กำลังสร้างออเดอร์...';

    const fd = new FormData();
    fd.append('action', 'buy_from_product');
    fd.append('product_id', productId);

    fetch('<?php echo BASE_URL; ?>/controllers/OrderController.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                window.location = data.redirect;
            } else {
                btn.disabled = false;
                btn.textContent = 'ซื้อเลย — เงินพักกลางปลอดภัย';
                Swal.fire('ไม่สามารถซื้อได้', data.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'ซื้อเลย — เงินพักกลางปลอดภัย';
            Swal.fire('ไม่สามารถซื้อได้', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่', 'error');
        });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
