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
                    <?php if ($rating || $seller_review_total > 0): ?>
                        <span class="product-trust-pill">
                            ★ <?php echo $rating ?: number_format($seller_review_stats['avg_score'] ?? 0, 1); ?>
                            · <?php echo number_format($seller_review_total); ?> รีวิว
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($product['seller_verified'])): ?>
                        <span class="product-trust-pill">✓ ยืนยันตัวตนแล้ว</span>
                    <?php endif; ?>
                    <span class="product-trust-pill">เงินพักกลางจนกว่าของถึงมือ</span>
                </div>

                <div class="product-detail-seller">
                    <div class="product-seller-avatar"><?php echo strtoupper(mb_substr($seller_display, 0, 1)); ?></div>
                    <div>
                        <div class="product-seller-name">
                            <?php echo htmlspecialchars($seller_display); ?>
                        </div>
                        <?php if ($rating): ?>
                            <div class="product-seller-rating">★ <?php echo $rating; ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isOwnProduct): ?>
                        <?php if ($is_logged_in): ?>
                            <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($product['seller_name']); ?>" class="btn-flixo-outline" style="margin-left:auto;padding:8px 14px;flex:0;">คุยกับผู้ขาย</a>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($login_redirect); ?>" class="btn-flixo-outline" style="margin-left:auto;padding:8px 14px;flex:0;">เข้าสู่ระบบเพื่อคุย</a>
                        <?php endif; ?>
                    <?php endif; ?>
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
