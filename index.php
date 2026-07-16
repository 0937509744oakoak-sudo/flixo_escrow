<?php
/**
 * Public landing + guest catalog (no login required)
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/includes/platform_rules.php';

if (!empty($_SESSION['user'])) {
    redirectTo('dashboard.php');
}

$productModel = new Product($conn);
$search_query = trim($_GET['search_product'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$allowed_sorts = ['newest', 'price_asc', 'price_desc'];
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'newest';
}

$catalog_per_page = 12;
$catalog_page = max(1, (int) ($_GET['page'] ?? 1));
$catalog_offset = ($catalog_page - 1) * $catalog_per_page;

$catalog_total = $productModel->countFiltered($category_filter, $search_query);
$catalog_products = $productModel->getFiltered($catalog_per_page, $catalog_offset, $category_filter, $sort, $search_query);
$catalog_total_pages = max(1, (int) ceil($catalog_total / $catalog_per_page));

$categories = ['อิเล็กทรอนิกส์', 'เครื่องใช้ไฟฟ้า', 'แฟชั่น', 'ยานยนต์', 'อื่นๆ'];

$page_title = 'ซื้อขายมือสองปลอดภัย';
$load_flixo_css = true;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';

$catalogPageUrl = static function (int $page) use ($search_query, $category_filter, $sort): string {
    $params = [];
    if ($search_query !== '') {
        $params['search_product'] = $search_query;
    }
    if ($category_filter !== '') {
        $params['category'] = $category_filter;
    }
    if ($sort !== 'newest') {
        $params['sort'] = $sort;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    $qs = http_build_query($params);
    return 'index.php' . ($qs !== '' ? '?' . $qs : '') . '#catalog';
};
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/landing.css?v=<?php echo time(); ?>">

<main class="landing-page">
    <section class="landing-hero">
        <div class="landing-hero-inner">
            <p class="landing-eyebrow">
                <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
                FLIXO — Escrow Marketplace ของมือสอง
            </p>
            <h1 class="landing-headline">
                ซื้อของมือสองแบบไม่ต้องเสี่ยง<br>
                <span class="landing-headline-accent">เงินไม่ถึงมือผู้ขาย จนกว่าคุณจะได้รับของ</span>
            </h1>
            <p class="landing-lead">
                ซื้อขายสบายใจด้วยระบบพักเงินกลาง (Escrow) ระบบไกล่เกลี่ยข้อพิพาท
                และกระเป๋าเงินออนไลน์ที่มี AI ช่วยดูแล — ผู้ขายได้รับเงินเมื่อคุณยืนยันรับสินค้าแล้วเท่านั้น
            </p>
            <div class="landing-cta-row">
                <a href="register.php" class="btn-flixo-primary landing-cta">เริ่มซื้อขายอย่างปลอดภัย</a>
                <a href="#catalog" class="btn-flixo-outline landing-cta-secondary">สำรวจตลาดมือสอง</a>
            </div>
            <p class="landing-trust-note">
                <span aria-hidden="true">⚡</span>
                ปลอดภัย 100% ด้วยระบบเงินฝากส่วนกลาง — คืนเงินเต็มจำนวนหากไม่ได้รับสินค้า
            </p>
        </div>
    </section>

    <section class="landing-how" aria-labelledby="how-title">
        <h2 id="how-title" class="landing-section-title">ใช้งาน 3 ขั้นตอน</h2>
        <p class="landing-section-sub">เข้าใจง่าย ไม่ต้องรู้คำว่า Escrow</p>
        <ol class="landing-steps">
            <li>
                <span class="landing-step-num">1</span>
                <strong>เลือกสินค้า</strong>
                <span>ดูประกาศได้ทันที ไม่ต้องล็อกอินก่อน</span>
            </li>
            <li>
                <span class="landing-step-num">2</span>
                <strong>ชำระเงินเข้า FLIXO</strong>
                <span>เงินพักไว้กลาง — ผู้ขายยังไม่ได้รับ</span>
            </li>
            <li>
                <span class="landing-step-num">3</span>
                <strong>รับของแล้วกดยืนยัน</strong>
                <span>ระบบโอนเงินให้ผู้ขายหลังคุณพอใจ</span>
            </li>
        </ol>
    </section>

    <section class="landing-features" aria-labelledby="features-title" id="features">
        <h2 id="features-title" class="landing-section-title">ระบบที่อยู่เบื้องหลังความปลอดภัย</h2>
        <p class="landing-section-sub">ทุกออเดอร์บน FLIXO ถูกคุ้มครองด้วยเครื่องมือเหล่านี้โดยอัตโนมัติ</p>
        <ul class="landing-features-grid">
            <li class="landing-feature-card">
                <span class="material-symbols-outlined landing-feature-icon" aria-hidden="true">account_balance</span>
                <strong>ระบบพักเงินกลาง (Escrow)</strong>
                <span>เงินของผู้ซื้อถูกเก็บไว้ที่ระบบกลาง ไม่โอนเข้าบัญชีผู้ขายจนกว่าผู้ซื้อจะกดยืนยันรับสินค้า</span>
            </li>
            <li class="landing-feature-card">
                <span class="material-symbols-outlined landing-feature-icon" aria-hidden="true">balance</span>
                <strong>ระบบไกล่เกลี่ยข้อพิพาท (Dispute)</strong>
                <span>ของไม่ตรงปกหรือมีปัญหา? เปิดข้อพิพาทได้ทันที มีทีมงานและระบบช่วยตัดสินอย่างเป็นธรรม</span>
            </li>
            <li class="landing-feature-card">
                <span class="material-symbols-outlined landing-feature-icon" aria-hidden="true">account_balance_wallet</span>
                <strong>กระเป๋าเงินออนไลน์ (Wallet)</strong>
                <span>รับเงิน ถอนเงิน และติดตามยอดได้ในที่เดียว พร้อมประวัติธุรกรรมครบทุกรายการ</span>
            </li>
            <li class="landing-feature-card">
                <span class="material-symbols-outlined landing-feature-icon" aria-hidden="true">smart_toy</span>
                <strong>AI ช่วยตรวจสอบ</strong>
                <span>AI ช่วยคัดกรองประกาศหลอกลวง ตรวจสลิปโอนเงิน และช่วยวิเคราะห์ข้อพิพาทให้เร็วขึ้น</span>
            </li>
        </ul>
    </section>

    <section class="landing-rules" aria-labelledby="rules-title" id="hard-rules">
        <h2 id="rules-title" class="landing-section-title">กฎเหล็กความปลอดภัย</h2>
        <p class="landing-section-sub">เรามีมาตรการควบคุม — ห้ามลงขายสินค้าผิดกฎหมาย ละเมิดลิขสิทธิ์ และสินค้าควบคุม</p>
        <ul class="landing-rules-grid">
            <?php foreach (flixo_hard_rules() as $rule): ?>
            <li class="landing-rule-card">
                <span class="material-symbols-outlined landing-rule-icon" aria-hidden="true"><?php echo htmlspecialchars($rule['icon']); ?></span>
                <strong><?php echo htmlspecialchars($rule['title']); ?></strong>
                <span><?php echo htmlspecialchars($rule['desc']); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="landing-rules-foot">
            ผู้ละเมิดอาจถูกถอดประกาศ ระงับบัญชี และส่งเรื่องต่อตามกฎหมาย
            <a href="terms_of_service.php#hard-rules">อ่านข้อกำหนดการใช้งานฉบับเต็ม</a>
        </p>
    </section>

    <section class="landing-catalog product-catalog-section" id="catalog">
        <div class="catalog-header">
            <div>
                <h2 class="catalog-title"><?php echo $search_query !== '' ? 'ผลการค้นหา' : 'ประกาศล่าสุด'; ?></h2>
                <p class="catalog-subtitle">
                    <?php if ($search_query !== ''): ?>
                        คำค้นหา: "<?php echo htmlspecialchars($search_query); ?>" — พบ <?php echo number_format($catalog_total); ?> รายการ
                    <?php else: ?>
                        เลือกดูได้เลย สมัครตอนพร้อมซื้อ
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <form class="landing-filters" method="get" action="index.php#catalog">
            <input type="search" name="search_product" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="ค้นหาสินค้า..." class="landing-search" aria-label="ค้นหาสินค้า">
            <select name="category" class="landing-select" aria-label="หมวดหมู่">
                <option value="">ทุกหมวดหมู่</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="landing-select" aria-label="เรียงลำดับ">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>ใหม่ล่าสุด</option>
                <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>ราคาต่ำ → สูง</option>
                <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>ราคาสูง → ต่ำ</option>
            </select>
            <button type="submit" class="btn-flixo-primary landing-filter-btn">ค้นหา</button>
        </form>

        <?php if ($catalog_products && $catalog_products->num_rows > 0): ?>
            <div class="product-grid">
                <?php while ($p = $catalog_products->fetch_assoc()):
                    $img = Product::imageUrl($p['image_path']);
                    $rating = $p['seller_rating'] ? number_format($p['seller_rating'], 1) : null;
                ?>
                <a href="product.php?id=<?php echo (int)$p['id']; ?>" class="product-card">
                    <div class="product-card-img">
                        <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($p['product_name']); ?>" loading="lazy" onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                    </div>
                    <div class="product-card-body">
                        <div class="product-card-name"><?php echo htmlspecialchars($p['product_name']); ?></div>
                        <div class="product-card-price">฿<?php echo number_format($p['price'], 0); ?></div>
                        <div class="product-card-seller">
                            <span class="product-card-seller-name"><?php echo htmlspecialchars($p['seller_display_name'] ?? $p['seller_name']); ?></span>
                            <?php if (!empty($p['seller_verified'])): ?>
                                <span class="product-card-verified" title="ยืนยันตัวตนแล้ว">✓</span>
                            <?php endif; ?>
                            <?php if ($rating): ?>
                                <span class="product-card-rating"><?php echo $rating; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endwhile; ?>
            </div>

            <?php if ($catalog_total_pages > 1): ?>
            <nav class="catalog-pagination" aria-label="เปลี่ยนหน้าประกาศ">
                <div class="catalog-pagination-controls">
                    <?php if ($catalog_page > 1): ?>
                        <a href="<?php echo htmlspecialchars($catalogPageUrl($catalog_page - 1)); ?>" class="catalog-page-btn" aria-label="หน้าก่อนหน้า">
                            <span class="material-symbols-outlined">chevron_left</span>
                        </a>
                    <?php endif; ?>
                    <span class="catalog-pagination-meta tx-meta">หน้า <?php echo $catalog_page; ?> / <?php echo $catalog_total_pages; ?></span>
                    <?php if ($catalog_page < $catalog_total_pages): ?>
                        <a href="<?php echo htmlspecialchars($catalogPageUrl($catalog_page + 1)); ?>" class="catalog-page-btn" aria-label="หน้าถัดไป">
                            <span class="material-symbols-outlined">chevron_right</span>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state-box catalog-empty">
                <span class="material-symbols-outlined">storefront</span>
                <p><?php echo $search_query !== '' || $category_filter !== '' ? 'ไม่พบประกาศที่ตรงเงื่อนไข' : 'ยังไม่มีประกาศ — เป็นคนแรกที่ลงขาย!'; ?></p>
                <a href="register.php" class="btn-flixo-primary catalog-empty-cta">สมัครแล้วลงประกาศ</a>
            </div>
        <?php endif; ?>
    </section>

    <section class="landing-bottom-cta">
        <h2>พร้อมซื้อขายอย่างมั่นใจ?</h2>
        <p>สมัครฟรี แล้วเริ่มซื้อหรือลงประกาศได้ทันที</p>
        <a href="register.php" class="btn-flixo-primary">สมัครสมาชิก</a>
        <p class="landing-login-hint">มีบัญชีแล้ว? <a href="login.php">เข้าสู่ระบบ</a></p>
    </section>
</main>

<footer class="landing-footer">
    <div class="landing-footer-inner">
        <span class="landing-footer-brand">FLIXO</span>
        <nav>
            <a href="privacy_policy.php">นโยบายความเป็นส่วนตัว</a>
            <a href="terms_of_service.php#hard-rules">ข้อกำหนดและกฎเหล็ก</a>
            <a href="mailto:<?php echo htmlspecialchars(defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS ? MAIL_FROM_ADDRESS : 'support@flixo.app'); ?>">ติดต่อฝ่ายสนับสนุน</a>
        </nav>
        <p>© <?php echo date('Y'); ?> FLIXO — เงินพักกลางจนกว่าของถึงมือ</p>
    </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>
