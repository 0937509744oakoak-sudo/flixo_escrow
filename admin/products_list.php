<?php
require 'admin_check.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/Rbac.php';

$role = $_SESSION['role'] ?? 'user';
if (!Rbac::can($role, 'products.manage')) {
    http_response_code(403);
    exit('Access Denied — ต้องมีสิทธิ์จัดการสินค้า');
}

$productModel = new Product($conn);
$csrf_token = getCsrfToken();

$deleteReasons = [
    'สินค้าผิดกฎหมายหรือผิดกฎแพลตฟอร์ม',
    'รูปภาพหรือรายละเอียดไม่ตรงกับสินค้าจริง',
    'สินค้าละเมิดลิขสิทธิ์ / ของปลอม',
    'ประกาศซ้ำหรือสแปม',
    'ข้อมูลติดต่อนอกระบบ / พยายามหลีกเลี่ยง Escrow',
    'อื่นๆ (ระบุในรายละเอียด)',
];

$warnReasons = [
    'กรุณาอัปเดตรูปสินค้าให้ชัดเจนและตรงกับของจริง',
    'กรุณาแก้ไขรายละเอียดสินค้าให้ครบถ้วนและถูกต้อง',
    'ราคาหรือเงื่อนไขดูไม่สมเหตุสมผล กรุณาตรวจสอบ',
    'หมวดหมู่ไม่ถูกต้อง กรุณาเลือกหมวดที่เหมาะสม',
    'พบเนื้อหาที่ไม่เหมาะสม กรุณาแก้ไข',
    'อื่นๆ (ระบุในรายละเอียด)',
];

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['product_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reasonChoice = trim((string) ($_POST['reason_choice'] ?? ''));
    $reasonDetail = trim((string) ($_POST['reason_detail'] ?? ''));

    $product = $productId > 0 ? $productModel->getById($productId) : null;
    if (!$product) {
        $flash = 'error|ไม่พบสินค้า';
    } else {
        $reasonParts = array_filter([$reasonChoice, $reasonDetail], static fn($v) => $v !== '');
        $reasonText = implode(' — ', $reasonParts);

        if ($reasonText === '') {
            $flash = 'error|กรุณาเลือกหรือระบุเหตุผล';
        } elseif ($action === 'warn') {
            $msg = "⚠️ แจ้งเตือนจากทีม FLIXO เกี่ยวกับประกาศ \"{$product['product_name']}\"\n\n"
                . "กรุณาปรับปรุง:\n{$reasonText}\n\n"
                . "แก้ไขได้ที่แท็บ \"ประกาศของฉัน\" หากไม่แก้ไข ประกาศอาจถูกระงับหรือลบในภายหลัง";

            createNotification($conn, $product['seller_name'], $msg, 'dashboard.php?tab=my_products');
            $flash = 'ok|ส่งคำเตือนถึงผู้ขายแล้ว';
        } elseif ($action === 'delete') {
            $seller = $product['seller_name'];
            $productName = $product['product_name'];

            if ($productModel->deleteByAdmin($productId)) {
                $msg = "🗑️ ประกาศ \"{$productName}\" ถูกลบโดยทีม FLIXO\n\n"
                    . "เหตุผล:\n{$reasonText}\n\n"
                    . "หากมีข้อสงสัย สามารถติดต่อฝ่ายสนับสนุนได้";

                createNotification($conn, $seller, $msg, 'dashboard.php?tab=my_products');
                $flash = 'ok|ลบสินค้าและแจ้งผู้ขายแล้ว';
            } else {
                $flash = 'error|ลบสินค้าไม่สำเร็จ';
            }
        } else {
            $flash = 'error|คำสั่งไม่ถูกต้อง';
        }
    }

    $qs = http_build_query(array_filter([
        'q' => trim((string) ($_GET['q'] ?? $_POST['q'] ?? '')),
        'page' => (int) ($_GET['page'] ?? 1) > 1 ? (int) ($_GET['page'] ?? 1) : null,
        'flash' => $flash,
    ]));
    header('Location: products_list.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = $productModel->countAdminList($search);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$products = $productModel->adminList($perPage, $offset, $search);

$flashRaw = (string) ($_GET['flash'] ?? '');
$flashType = '';
$flashMsg = '';
if ($flashRaw !== '' && str_contains($flashRaw, '|')) {
    [$flashType, $flashMsg] = explode('|', $flashRaw, 2);
}

$page_title = 'จัดการสินค้า';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>📦 จัดการสินค้า</h1>
                <p>เตือนผู้ขายให้แก้ไข หรือลบประกาศพร้อมแจ้งเหตุผล</p>
            </div>
        </div>

        <?php if ($flashMsg !== ''): ?>
            <div class="admin-panel" style="margin-bottom:16px;border-left:4px solid <?php echo $flashType === 'ok' ? '#16a34a' : '#dc2626'; ?>;">
                <div class="admin-panel-body" style="padding:12px 16px;">
                    <?php echo $flashType === 'ok' ? '✅' : '❌'; ?>
                    <?php echo safeText($flashMsg); ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="admin-panel" style="margin-bottom:16px;">
            <div class="admin-panel-body" style="padding:12px 16px;">
                <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <input type="search" name="q" value="<?php echo safeText($search); ?>"
                           placeholder="ค้นหาชื่อสินค้า / ผู้ขาย / รายละเอียด..."
                           style="flex:1;min-width:220px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;">
                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">ค้นหา</button>
                    <?php if ($search !== ''): ?>
                        <a href="products_list.php" class="admin-text-muted">ล้างตัวกรอง</a>
                    <?php endif; ?>
                    <span class="admin-text-muted" style="margin-left:auto;">ทั้งหมด <?php echo number_format($total); ?> รายการ</span>
                </form>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>สินค้า</th>
                            <th>ผู้ขาย</th>
                            <th>ราคา</th>
                            <th>สถานะรูป</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($products && $products->num_rows > 0): while ($row = $products->fetch_assoc()):
                        $status = $row['image_fraud_status'] ?? 'pending';
                        $badgeClass = match ($status) {
                            'passed', 'skipped' => 'admin-badge-done',
                            'flagged' => 'admin-badge-flagged',
                            'rejected' => 'admin-badge-rejected',
                            default => 'admin-badge-pending',
                        };
                        $imgUrl = Product::imageUrl($row['image_path'] ?? '');
                    ?>
                        <tr>
                            <td>
                                <div class="admin-product-cell">
                                    <img class="admin-thumb" src="<?php echo htmlspecialchars($imgUrl); ?>" alt=""
                                         onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                                    <div>
                                        <strong><?php echo safeText($row['product_name']); ?></strong><br>
                                        <span class="admin-text-muted">#<?php echo (int) $row['id']; ?>
                                            <?php if (!empty($row['category'])): ?>
                                                · <?php echo safeText($row['category']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo safeText($row['seller_display_name'] ?? $row['seller_name']); ?></td>
                            <td>฿<?php echo number_format((float) $row['price'], 0); ?></td>
                            <td><span class="admin-badge <?php echo $badgeClass; ?>"><?php echo safeText($status); ?></span></td>
                            <td>
                                <div class="admin-action-group">
                                    <button type="button" class="admin-btn admin-btn-warning admin-btn-sm"
                                            data-open-modal="warn"
                                            data-product-id="<?php echo (int) $row['id']; ?>"
                                            data-product-name="<?php echo safeText($row['product_name']); ?>">
                                        เตือน
                                    </button>
                                    <button type="button" class="admin-btn admin-btn-danger admin-btn-sm"
                                            data-open-modal="delete"
                                            data-product-id="<?php echo (int) $row['id']; ?>"
                                            data-product-name="<?php echo safeText($row['product_name']); ?>">
                                        ลบ
                                    </button>
                                    <a href="../product.php?id=<?php echo (int) $row['id']; ?>" target="_blank" class="admin-text-muted">ดู →</a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="empty">ไม่พบสินค้า</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <div style="display:flex;gap:8px;justify-content:center;padding:16px;flex-wrap:wrap;">
                        <?php for ($i = 1; $i <= $totalPages; $i++):
                            $url = 'products_list.php?' . http_build_query(array_filter(['q' => $search !== '' ? $search : null, 'page' => $i > 1 ? $i : null]));
                        ?>
                            <a href="<?php echo htmlspecialchars($url); ?>"
                               class="admin-btn admin-btn-sm <?php echo $i === $page ? 'admin-btn-primary' : 'admin-btn-muted'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Warn / Delete modal -->
<div id="product-mod-modal" hidden style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:none;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:12px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 40px rgba(0,0,0,.15);">
        <h2 id="mod-modal-title" style="margin:0 0 8px;font-size:18px;">จัดการสินค้า</h2>
        <p id="mod-modal-subtitle" class="admin-text-muted" style="margin:0 0 16px;"></p>
        <form method="post" id="mod-modal-form">
            <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
            <input type="hidden" name="product_id" id="mod-product-id" value="">
            <input type="hidden" name="action" id="mod-action" value="">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="q" value="<?php echo safeText($search); ?>">
            <?php endif; ?>

            <label style="display:block;font-weight:600;margin-bottom:6px;">เหตุผล</label>
            <select name="reason_choice" id="mod-reason-choice" required
                    style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:12px;">
            </select>

            <label style="display:block;font-weight:600;margin-bottom:6px;">รายละเอียดเพิ่มเติม (ถ้ามี)</label>
            <textarea name="reason_detail" id="mod-reason-detail" rows="3"
                      placeholder="อธิบายเพิ่มเติมให้ผู้ขายเข้าใจ..."
                      style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;resize:vertical;"></textarea>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                <button type="button" class="admin-btn admin-btn-muted admin-btn-sm" id="mod-modal-cancel">ยกเลิก</button>
                <button type="submit" class="admin-btn admin-btn-sm" id="mod-modal-submit">ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('product-mod-modal');
    const title = document.getElementById('mod-modal-title');
    const subtitle = document.getElementById('mod-modal-subtitle');
    const productIdInput = document.getElementById('mod-product-id');
    const actionInput = document.getElementById('mod-action');
    const reasonSelect = document.getElementById('mod-reason-choice');
    const submitBtn = document.getElementById('mod-modal-submit');
    const form = document.getElementById('mod-modal-form');

    const warnReasons = <?php echo json_encode($warnReasons, JSON_UNESCAPED_UNICODE); ?>;
    const deleteReasons = <?php echo json_encode($deleteReasons, JSON_UNESCAPED_UNICODE); ?>;

    function fillReasons(list) {
        reasonSelect.innerHTML = '';
        list.forEach(function (text) {
            const opt = document.createElement('option');
            opt.value = text;
            opt.textContent = text;
            reasonSelect.appendChild(opt);
        });
    }

    function openModal(mode, productId, productName) {
        productIdInput.value = productId;
        actionInput.value = mode;
        document.getElementById('mod-reason-detail').value = '';

        if (mode === 'warn') {
            title.textContent = 'เตือนผู้ขายให้แก้ไข';
            subtitle.textContent = 'สินค้า: ' + productName + ' — จะส่งการแจ้งเตือนไปยังผู้ขายโดยไม่ลบประกาศ';
            fillReasons(warnReasons);
            submitBtn.textContent = 'ส่งคำเตือน';
            submitBtn.className = 'admin-btn admin-btn-warning admin-btn-sm';
        } else {
            title.textContent = 'ลบสินค้าและแจ้งผู้ขาย';
            subtitle.textContent = 'สินค้า: ' + productName + ' — ประกาศจะถูกลบถาวร และผู้ขายจะได้รับเหตุผล';
            fillReasons(deleteReasons);
            submitBtn.textContent = 'ยืนยันลบ';
            submitBtn.className = 'admin-btn admin-btn-danger admin-btn-sm';
        }

        modal.hidden = false;
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.hidden = true;
        modal.style.display = 'none';
    }

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(
                btn.getAttribute('data-open-modal'),
                btn.getAttribute('data-product-id'),
                btn.getAttribute('data-product-name') || ''
            );
        });
    });

    document.getElementById('mod-modal-cancel').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });

    form.addEventListener('submit', function (e) {
        if (actionInput.value === 'delete') {
            if (!confirm('ยืนยันลบประกาศนี้ถาวร? ผู้ขายจะได้รับการแจ้งเตือนพร้อมเหตุผล')) {
                e.preventDefault();
            }
        }
    });
})();
</script>

<?php include 'layout_footer.php'; ?>
