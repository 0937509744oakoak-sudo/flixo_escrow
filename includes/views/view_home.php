<?php
/** Home tab: product catalog with category + sort filters */
$search_query = $search_product ?? trim($_GET['search_product'] ?? '');
$category_filter = $category_filter ?? trim($_GET['category'] ?? '');
$catalog_sort = $catalog_sort ?? ($_GET['sort'] ?? 'newest');
$catalog_page = (int) ($catalog_page ?? 1);
$catalog_total = (int) ($catalog_total ?? 0);
$catalog_total_pages = (int) ($catalog_total_pages ?? 1);
$catalog_per_page = (int) ($catalog_per_page ?? 24);
$catalog_from = $catalog_total > 0 ? (($catalog_page - 1) * $catalog_per_page) + 1 : 0;
$catalog_to = min($catalog_total, $catalog_page * $catalog_per_page);

$categories = ['อิเล็กทรอนิกส์', 'เครื่องใช้ไฟฟ้า', 'แฟชั่น', 'ยานยนต์', 'อื่นๆ'];

$catalogPageUrl = static function (int $page) use ($search_query, $category_filter, $catalog_sort): string {
    $params = [];
    if ($search_query !== '') {
        $params['search_product'] = $search_query;
    }
    if ($category_filter !== '') {
        $params['category'] = $category_filter;
    }
    if ($catalog_sort !== 'newest') {
        $params['sort'] = $catalog_sort;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    $qs = http_build_query($params);
    return 'dashboard.php' . ($qs !== '' ? '?' . $qs : '');
};

$filterUrl = static function (string $cat = '') use ($search_query, $catalog_sort): string {
    $params = [];
    if ($search_query !== '') {
        $params['search_product'] = $search_query;
    }
    if ($cat !== '') {
        $params['category'] = $cat;
    }
    if ($catalog_sort !== 'newest') {
        $params['sort'] = $catalog_sort;
    }
    $qs = http_build_query($params);
    return 'dashboard.php' . ($qs !== '' ? '?' . $qs : '');
};
?>
<link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/landing.css?v=<?php echo time(); ?>">
<?php if ($todo_count > 0): ?>
<div class="home-alert-strip">
    <span class="material-symbols-outlined">pending_actions</span>
    <span>คุณมี <strong><?php echo (int) $todo_count; ?></strong> ออเดอร์รอดำเนินการ</span>
    <button type="button" class="home-alert-link" data-app-tab="orders">ดูรายการ</button>
</div>
<?php endif; ?>

<?php if ($search_query !== '' || $category_filter !== ''): ?>
<div class="catalog-search-clear">
    <a href="dashboard.php" class="link-primary">← ดูประกาศทั้งหมด</a>
</div>
<?php endif; ?>

<section class="product-catalog-section">
    <div class="catalog-header">
        <div>
            <h2 class="catalog-title">
                <?php echo $search_query !== '' ? 'ผลการค้นหา' : 'ประกาศล่าสุด'; ?>
            </h2>
            <?php if ($search_query !== ''): ?>
                <p class="catalog-subtitle">คำค้นหา: "<?php echo htmlspecialchars($search_query); ?>" — พบ <?php echo number_format($catalog_total); ?> รายการ</p>
            <?php else: ?>
                <p class="catalog-subtitle">เงินพักกลางจนกว่าของถึงมือ — ไม่ต้องโอนให้คนแปลกหน้าโดยตรง</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="catalog-filters">
        <a href="<?php echo htmlspecialchars($filterUrl('')); ?>" class="catalog-filter-chip<?php echo $category_filter === '' ? ' is-active' : ''; ?>">ทั้งหมด</a>
        <?php foreach ($categories as $cat): ?>
            <a href="<?php echo htmlspecialchars($filterUrl($cat)); ?>" class="catalog-filter-chip<?php echo $category_filter === $cat ? ' is-active' : ''; ?>">
                <?php echo htmlspecialchars($cat); ?>
            </a>
        <?php endforeach; ?>
        <form method="get" action="dashboard.php" class="catalog-sort-form" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <?php if ($search_query !== ''): ?>
                <input type="hidden" name="search_product" value="<?php echo htmlspecialchars($search_query); ?>">
            <?php endif; ?>
            <?php if ($category_filter !== ''): ?>
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
            <?php endif; ?>
            <label for="catalog-sort" class="tx-meta" style="margin:0;">เรียง</label>
            <select name="sort" id="catalog-sort" class="catalog-sort-select" onchange="this.form.submit()">
                <option value="newest" <?php echo $catalog_sort === 'newest' ? 'selected' : ''; ?>>ใหม่ล่าสุด</option>
                <option value="price_asc" <?php echo $catalog_sort === 'price_asc' ? 'selected' : ''; ?>>ราคาต่ำ → สูง</option>
                <option value="price_desc" <?php echo $catalog_sort === 'price_desc' ? 'selected' : ''; ?>>ราคาสูง → ต่ำ</option>
            </select>
        </form>
    </div>

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
                            <span class="product-card-rating">★ <?php echo $rating; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endwhile; ?>
        </div>

        <?php if ($catalog_total_pages > 1): ?>
        <nav class="catalog-pagination" aria-label="เปลี่ยนหน้าประกาศ">
            <p class="catalog-pagination-meta tx-meta">
                แสดง <?php echo number_format($catalog_from); ?>–<?php echo number_format($catalog_to); ?> จาก <?php echo number_format($catalog_total); ?> รายการ
            </p>
            <div class="catalog-pagination-controls">
                <?php if ($catalog_page > 1): ?>
                    <a href="<?php echo htmlspecialchars($catalogPageUrl($catalog_page - 1)); ?>" class="catalog-page-btn" aria-label="หน้าก่อนหน้า">
                        <span class="material-symbols-outlined">chevron_left</span>
                    </a>
                <?php else: ?>
                    <span class="catalog-page-btn is-disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_left</span></span>
                <?php endif; ?>

                <?php
                $page_window = 2;
                $start_page = max(1, $catalog_page - $page_window);
                $end_page = min($catalog_total_pages, $catalog_page + $page_window);
                for ($p = $start_page; $p <= $end_page; $p++):
                ?>
                    <a href="<?php echo htmlspecialchars($catalogPageUrl($p)); ?>"
                       class="catalog-page-num<?php echo $p === $catalog_page ? ' is-active' : ''; ?>"
                       <?php echo $p === $catalog_page ? 'aria-current="page"' : ''; ?>>
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($catalog_page < $catalog_total_pages): ?>
                    <a href="<?php echo htmlspecialchars($catalogPageUrl($catalog_page + 1)); ?>" class="catalog-page-btn" aria-label="หน้าถัดไป">
                        <span class="material-symbols-outlined">chevron_right</span>
                    </a>
                <?php else: ?>
                    <span class="catalog-page-btn is-disabled" aria-hidden="true"><span class="material-symbols-outlined">chevron_right</span></span>
                <?php endif; ?>
            </div>
        </nav>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state-box catalog-empty">
            <span class="material-symbols-outlined">storefront</span>
            <?php if ($search_query !== '' || $category_filter !== ''): ?>
                <p>ไม่พบประกาศที่ตรงเงื่อนไข</p>
            <?php else: ?>
                <p>ยังไม่มีประกาศในระบบ — เป็นคนแรกที่ลงขายของมือสอง!</p>
                <button type="button" class="btn-flixo-primary catalog-empty-cta" data-quick-tab="sell">ลงประกาศขาย</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
