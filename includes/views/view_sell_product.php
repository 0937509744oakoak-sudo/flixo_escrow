<?php
/** Sell product tab — multi-image listing form */
$csrf_token = safeText(getCsrfToken());
$max_images = Product::MAX_IMAGES;
?>
<div class="sell-page">
    <header class="sell-page-header">
        <h1 class="sell-page-title">ลงประกาศขาย</h1>
        <p class="sell-page-subtitle">ขายของมือสองได้มั่นใจ — เงินผู้ซื้อพักไว้กับ FLIXO จนกว่าผู้ซื้อยืนยันรับของ</p>
    </header>

    <?php if (!$seller_verified): ?>
    <div class="sell-kyc-banner" style="margin-bottom:16px;padding:14px 16px;background:#fff8e1;border:1px solid #ffe082;border-radius:12px;font-size:14px;">
        <strong>ยังไม่ยืนยันตัวตน</strong> —
        <a href="kyc_verify.php" class="link-primary">ยืนยัน eKYC</a>
        เพื่อเพิ่มความน่าเชื่อถือ<?php if (defined('EKYC_REQUIRED_FOR_SELL') && EKYC_REQUIRED_FOR_SELL): ?> (จำเป็นก่อนลงประกาศ)<?php endif; ?>
    </div>
    <?php endif; ?>

    <section class="sell-form-card">
        <form id="sellForm" class="sell-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="sell_product">

            <div class="sell-field-block">
                <div class="sell-image-header">
                    <label class="sell-field-title">รูปภาพสินค้า</label>
                    <span class="sell-image-counter" id="sell-image-counter">0 / <?php echo $max_images; ?></span>
                </div>
                <div class="sell-image-grid" id="sell-image-grid" data-max="<?php echo $max_images; ?>">
                    <label class="sell-image-slot sell-image-upload" id="sell-image-add" for="sell-file-picker">
                        <span class="material-symbols-outlined">add_a_photo</span>
                        <span>เพิ่มรูปภาพ</span>
                    </label>
                </div>
                <input type="file" id="sell-file-picker" accept="image/jpeg,image/png,image/webp" multiple class="sell-file-input">
                <p class="sell-field-hint">แนะนำ: รูปถ่ายเองชัดๆ พื้นหลังเรียบ — JPG, PNG, WebP สูงสุด <?php echo $max_images; ?> รูป (รูปแรกเป็นรูปหลัก)</p>
                <p class="sell-field-hint">รูปที่ถ่ายจากมือถือจะช่วยให้ประกาศน่าเชื่อถือและผ่านการตรวจสอบเร็วขึ้น</p>
            </div>

            <hr class="sell-form-divider">

            <div class="sell-form-grid">
                <div class="sell-field sell-field-full">
                    <label class="sell-label" for="sell-product-name">ชื่อสินค้า</label>
                    <input class="sell-input" type="text" id="sell-product-name" name="product_name" maxlength="100" placeholder="เช่น iPhone 15 Pro Max 256GB" required>
                </div>

                <div class="sell-field">
                    <label class="sell-label" for="sell-product-category">หมวดหมู่สินค้า</label>
                    <select class="sell-input sell-select" id="sell-product-category" name="product_category">
                        <option value="">เลือกหมวดหมู่</option>
                        <option value="อิเล็กทรอนิกส์">อิเล็กทรอนิกส์</option>
                        <option value="เครื่องใช้ไฟฟ้า">เครื่องใช้ไฟฟ้า</option>
                        <option value="แฟชั่น">แฟชั่น</option>
                        <option value="ยานยนต์">ยานยนต์</option>
                        <option value="อื่นๆ">อื่นๆ</option>
                    </select>
                </div>

                <div class="sell-field">
                    <label class="sell-label" for="sell-product-price">ราคา (THB)</label>
                    <div class="sell-price-wrap">
                        <input class="sell-input sell-input-price" type="number" id="sell-product-price" name="price" min="1" step="0.01" placeholder="0.00" required>
                        <span class="sell-price-symbol">฿</span>
                    </div>
                </div>

                <div class="sell-field sell-field-full">
                    <label class="sell-label" for="sell-product-desc">รายละเอียดสินค้า</label>
                    <textarea class="sell-input sell-textarea" id="sell-product-desc" name="description" rows="5" maxlength="1000" placeholder="ระบุรายละเอียด สภาพสินค้า และการรับประกัน..."></textarea>
                </div>
            </div>

            <div class="sell-escrow-notice">
                <span class="material-symbols-outlined sell-escrow-icon">shield_with_heart</span>
                <div>
                    <h4 class="sell-escrow-title">เงินพักกลางปลอดภัย</h4>
                    <p class="sell-escrow-text">เงินของผู้ซื้อจะถูกเก็บไว้กับ FLIXO และโอนให้คุณเมื่อผู้ซื้อยืนยันว่ารับสินค้าครบแล้วเท่านั้น</p>
                </div>
            </div>

            <?php
            require_once __DIR__ . '/../platform_rules.php';
            $hardRules = flixo_hard_rules();
            ?>
            <div class="sell-rules-box">
                <div class="sell-rules-head">
                    <span class="material-symbols-outlined">policy</span>
                    <div>
                        <h4 class="sell-rules-title">กฎเหล็กการลงประกาศ</h4>
                        <p class="sell-rules-lead">ห้ามลงขายสินค้าผิดกฎหมาย สินค้าละเมิดลิขสิทธิ์ หรือสินค้าควบคุม</p>
                    </div>
                </div>
                <ul class="sell-rules-list">
                    <?php foreach ($hardRules as $rule): ?>
                        <li><strong><?php echo htmlspecialchars($rule['title']); ?></strong> — <?php echo htmlspecialchars($rule['desc']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <label class="sell-rules-consent">
                    <input type="checkbox" name="consent_listing_rules" id="sell-consent-rules" value="1" required>
                    <span>
                        ข้าพเจ้ายืนยันว่าสินค้านี้ไม่ผิดกฎหมาย ไม่ละเมิดลิขสิทธิ์ ไม่ใช่สินค้าควบคุม
                        และยอมรับ <a href="terms_of_service.php#hard-rules" target="_blank" rel="noopener">ข้อกำหนดการใช้งาน</a>
                    </span>
                </label>
            </div>

            <div class="sell-form-actions">
                <button type="submit" class="sell-btn-primary" id="sell-submit-btn">
                    <span class="sell-btn-text">ลงประกาศขายสินค้า</span>
                    <span class="material-symbols-outlined">send</span>
                </button>
                <button type="button" class="sell-btn-secondary" id="sell-draft-btn">บันทึกร่างดราฟต์</button>
            </div>
        </form>
    </section>

    <p class="sell-terms">
        <a href="terms_of_service.php#hard-rules">
            <span class="material-symbols-outlined">info</span>
            อ่านข้อกำหนดและกฎเหล็กสินค้าต้องห้าม
        </a>
    </p>
</div>

<script>
(function () {
    const MAX_IMAGES = <?php echo (int) $max_images; ?>;
    const DRAFT_KEY = 'flixo_sell_draft_v2';
    const form = document.getElementById('sellForm');
    if (!form) return;

    const grid = document.getElementById('sell-image-grid');
    const addSlot = document.getElementById('sell-image-add');
    const filePicker = document.getElementById('sell-file-picker');
    const counterEl = document.getElementById('sell-image-counter');
    const submitBtn = document.getElementById('sell-submit-btn');
    const draftBtn = document.getElementById('sell-draft-btn');

    /** @type {{ id: string, file: File, url: string }[]} */
    let images = [];

    function uid() {
        return 'img_' + Math.random().toString(36).slice(2, 11);
    }

    function updateCounter() {
        counterEl.textContent = images.length + ' / ' + MAX_IMAGES;
        addSlot.classList.toggle('is-hidden', images.length >= MAX_IMAGES);
    }

    function revokeAll() {
        images.forEach(function (item) {
            if (item.url) URL.revokeObjectURL(item.url);
        });
    }

    function addImageFiles(fileList) {
        const files = Array.from(fileList || []);
        for (let i = 0; i < files.length; i++) {
            if (images.length >= MAX_IMAGES) break;
            const file = files[i];
            if (!file.type || !file.type.startsWith('image/')) continue;
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire('ไฟล์ใหญ่เกินไป', file.name + ' เกิน 5MB', 'warning');
                continue;
            }
            images.push({ id: uid(), file: file, url: URL.createObjectURL(file) });
        }
        renderGrid();
    }

    function removeImage(id) {
        const idx = images.findIndex(function (item) { return item.id === id; });
        if (idx === -1) return;
        URL.revokeObjectURL(images[idx].url);
        images.splice(idx, 1);
        renderGrid();
    }

    function moveImage(id, direction) {
        const idx = images.findIndex(function (item) { return item.id === id; });
        if (idx === -1) return;
        const next = idx + direction;
        if (next < 0 || next >= images.length) return;
        const temp = images[idx];
        images[idx] = images[next];
        images[next] = temp;
        renderGrid();
    }

    function setPrimary(id) {
        const idx = images.findIndex(function (item) { return item.id === id; });
        if (idx <= 0) return;
        const item = images.splice(idx, 1)[0];
        images.unshift(item);
        renderGrid();
    }

    function renderGrid() {
        grid.querySelectorAll('.sell-image-preview').forEach(function (el) { el.remove(); });

        images.forEach(function (item, index) {
            const slot = document.createElement('div');
            slot.className = 'sell-image-slot sell-image-preview';
            slot.dataset.id = item.id;
            slot.innerHTML =
                '<img src="' + item.url + '" alt="ตัวอย่างรูปสินค้า ' + (index + 1) + '">' +
                (index === 0 ? '<span class="sell-image-badge">รูปหลัก</span>' : '') +
                '<div class="sell-image-actions">' +
                    (index > 0 ? '<button type="button" class="sell-image-action" data-action="primary" title="ตั้งเป็นรูปหลัก"><span class="material-symbols-outlined">star</span></button>' : '') +
                    '<button type="button" class="sell-image-action" data-action="left" title="เลื่อนไปซ้าย"' + (index === 0 ? ' disabled' : '') + '><span class="material-symbols-outlined">chevron_left</span></button>' +
                    '<button type="button" class="sell-image-action" data-action="right" title="เลื่อนไปขวา"' + (index === images.length - 1 ? ' disabled' : '') + '><span class="material-symbols-outlined">chevron_right</span></button>' +
                    '<button type="button" class="sell-image-action sell-image-action-danger" data-action="remove" title="ลบรูป"><span class="material-symbols-outlined">delete</span></button>' +
                '</div>';

            slot.querySelectorAll('.sell-image-action').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const action = btn.getAttribute('data-action');
                    if (action === 'remove') removeImage(item.id);
                    else if (action === 'left') moveImage(item.id, -1);
                    else if (action === 'right') moveImage(item.id, 1);
                    else if (action === 'primary') setPrimary(item.id);
                });
            });

            grid.insertBefore(slot, addSlot);
        });

        updateCounter();
    }

    filePicker.addEventListener('change', function () {
        addImageFiles(filePicker.files);
        filePicker.value = '';
    });

    grid.addEventListener('dragover', function (e) {
        e.preventDefault();
        grid.classList.add('is-dragover');
    });
    grid.addEventListener('dragleave', function () {
        grid.classList.remove('is-dragover');
    });
    grid.addEventListener('drop', function (e) {
        e.preventDefault();
        grid.classList.remove('is-dragover');
        addImageFiles(e.dataTransfer.files);
    });

    function getDraftData() {
        return {
            product_name: document.getElementById('sell-product-name').value,
            product_category: document.getElementById('sell-product-category').value,
            price: document.getElementById('sell-product-price').value,
            description: document.getElementById('sell-product-desc').value,
        };
    }

    function applyDraft(data) {
        if (!data) return;
        if (data.product_name) document.getElementById('sell-product-name').value = data.product_name;
        if (data.product_category) document.getElementById('sell-product-category').value = data.product_category;
        if (data.price) document.getElementById('sell-product-price').value = data.price;
        if (data.description) document.getElementById('sell-product-desc').value = data.description;
    }

    try {
        const saved = localStorage.getItem(DRAFT_KEY);
        if (saved) applyDraft(JSON.parse(saved));
    } catch (e) { /* ignore */ }

    draftBtn.addEventListener('click', function () {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(getDraftData()));
            Swal.fire({ icon: 'success', title: 'บันทึกร่างแล้ว', text: 'ข้อความและข้อมูลฟอร์มถูกเก็บไว้ (รูปภาพต้องเลือกใหม่)', timer: 2500, showConfirmButton: false });
        } catch (e) {
            Swal.fire('ไม่สามารถบันทึกร่างได้', 'เบราว์เซอร์ไม่รองรับการเก็บข้อมูล', 'warning');
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        if (images.length === 0) {
            Swal.fire('กรุณาเพิ่มรูปภาพ', 'อัปโหลดรูปสินค้าอย่างน้อย 1 รูป', 'warning');
            return;
        }

        const rulesConsent = document.getElementById('sell-consent-rules');
        if (!rulesConsent || !rulesConsent.checked) {
            Swal.fire('กรุณายืนยันกฎเหล็ก', 'ต้องยอมรับว่าสินค้าไม่ผิดกฎหมาย ไม่ละเมิดลิขสิทธิ์ และไม่ใช่สินค้าควบคุม', 'warning');
            return;
        }

        const formData = new FormData();
        const csrfToken = (typeof window.CSRF_TOKEN !== 'undefined' && window.CSRF_TOKEN)
            ? window.CSRF_TOKEN
            : (form.querySelector('[name="csrf_token"]') || {}).value || '';
        formData.set('csrf_token', csrfToken);
        formData.append('action', 'sell_product');
        formData.append('product_name', document.getElementById('sell-product-name').value);
        formData.append('product_category', document.getElementById('sell-product-category').value);
        formData.append('price', document.getElementById('sell-product-price').value);
        formData.append('description', document.getElementById('sell-product-desc').value);
        formData.append('consent_listing_rules', '1');

        images.forEach(function (item) {
            formData.append('product_images[]', item.file);
        });

        const btnText = submitBtn.querySelector('.sell-btn-text');
        const originalText = btnText.textContent;
        submitBtn.disabled = true;
        btnText.textContent = 'กำลังตรวจสอบรูปภาพ...';

        fetch('controllers/ProductController.php', {
            method: 'POST',
            body: formData,
            credentials: 'include',
            headers: { 'X-CSRF-Token': csrfToken }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    localStorage.removeItem(DRAFT_KEY);
                    revokeAll();
                    images = [];
                    renderGrid();
                    form.reset();
                    const successText = data.flagged
                        ? (data.message || 'ลงประกาศแล้ว แต่รูปภาพอยู่ระหว่างตรวจสอบเพิ่มเติม')
                        : 'ลงประกาศขายสินค้าเรียบร้อย (' + (data.image_count || 0) + ' รูป)';
                    Swal.fire(data.flagged ? 'รอตรวจสอบ' : 'สำเร็จ', successText, data.flagged ? 'info' : 'success').then(function () {
                        if (typeof window.flixoSwitchTab === 'function') {
                            window.location.href = 'dashboard.php';
                        }
                    });
                } else {
                    Swal.fire('ไม่สำเร็จ', data.message || 'เกิดข้อผิดพลาด', 'error');
                }
            })
            .catch(function () {
                Swal.fire('ไม่สำเร็จ', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
            })
            .finally(function () {
                submitBtn.disabled = false;
                btnText.textContent = originalText;
            });
    });

    renderGrid();
})();
</script>
