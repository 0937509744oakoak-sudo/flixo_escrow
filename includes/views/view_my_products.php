<h2 class="flixo-section-title">📦 ประกาศของฉัน</h2>
<div class="my-products-grid">
    <?php
    $my_products = $productModel->getBySeller($me, 50);
    if ($my_products->num_rows > 0):
        while ($p = $my_products->fetch_assoc()):
            $fraudStatus = $p['image_fraud_status'] ?? 'skipped';
            $fraudLabel = '';
            if ($fraudStatus === 'flagged') {
                $fraudLabel = '<span style="font-size:11px;color:#e65100;">⏳ รอตรวจสอบรูป</span>';
            } elseif ($fraudStatus === 'rejected') {
                $fraudLabel = '<span style="font-size:11px;color:#c62828;">❌ รูปไม่ผ่าน</span>';
            } elseif ($fraudStatus === 'passed') {
                $fraudLabel = '<span style="font-size:11px;color:#2e7d32;">✓ รูปผ่าน AI</span>';
            }
    ?>
        <div class="glass-card product-item-mini" id="my-product-<?php echo $p['id']; ?>">
            <div style="display:flex; gap:12px; align-items:center;">
                <img src="<?php echo htmlspecialchars(Product::imageUrl($p['image_path'])); ?>" alt="" style="width:60px; height:60px; border-radius:8px; object-fit:cover;" onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>
                <div style="flex:1;">
                    <h4 style="margin:0; font-size:14px;"><?php echo htmlspecialchars($p['product_name']); ?></h4>
                    <p style="margin:4px 0 0; font-weight:bold; color:var(--flixo-primary);">฿<?php echo number_format($p['price'], 2); ?></p>
                    <?php if ($fraudLabel): ?><p style="margin:4px 0 0;"><?php echo $fraudLabel; ?></p><?php endif; ?>
                </div>
                <div class="product-actions">
                    <button class="btn-icon-danger delete-product" data-id="<?php echo $p['id']; ?>" title="ลบสินค้า">
                        <span class="material-symbols-outlined">delete</span>
                    </button>
                </div>
            </div>
        </div>
    <?php 
        endwhile;
    else:
    ?>
        <div class="empty-state" style="text-align:center; padding:40px;">
            <span class="material-symbols-outlined" style="font-size:48px; color:#ccc;">inventory_2</span>
            <p>ยังไม่มีประกาศที่ลงไว้</p>
            <button class="btn-primary-mini" onclick="document.querySelector('[data-quick-tab=sell]').click()">ลงประกาศชิ้นแรก</button>
        </div>
    <?php endif; ?>
</div>

<style>
.my-products-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    max-width: 880px;
    margin: 0 auto;
}
@media (min-width: 768px) {
    .my-products-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
.product-item-mini {
    padding: 12px;
}
.btn-icon-danger {
    background: none;
    border: none;
    color: #ff4d4d;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}
.btn-icon-danger:hover {
    background: rgba(255, 77, 77, 0.1);
}
.btn-primary-mini {
    background: var(--flixo-primary);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    margin-top: 12px;
}
</style>

<script>
document.querySelectorAll('.delete-product').forEach(btn => {
    btn.addEventListener('click', function() {
        const productId = this.dataset.id;
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณไม่สามารถย้อนกลับการกระทำนี้ได้",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ff4d4d',
            cancelButtonColor: '#888',
            confirmButtonText: 'ใช่, ลบเลย',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'delete_product');
                formData.append('product_id', productId);
                formData.append('csrf_token', '<?php echo getCsrfToken(); ?>');

                fetch('controllers/ProductController.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('my-product-' + productId).remove();
                        Swal.fire('สำเร็จ', 'ลบสินค้าเรียบร้อย', 'success');
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                });
            }
        });
    });
});
</script>