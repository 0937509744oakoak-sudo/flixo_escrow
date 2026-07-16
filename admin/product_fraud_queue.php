<?php
require 'admin_check.php';
adminRequirePermission('moderation.queue');
require_once __DIR__ . '/../models/Product.php';

$productModel = new Product($conn);
$csrf_token = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['product_id'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $productId = (int)$_POST['product_id'];
    $action = $_POST['action'];

    if ($action === 'approve') {
        $productBefore = $productModel->getById($productId);
        $wasHidden = $productBefore && !$productModel->isPubliclyVisible($productBefore);

        $productModel->updateFraudStatus($productId, 'passed', 0, ['อนุมัติโดยแอดมิน']);

        // เพิ่งเปลี่ยนจากซ่อน → แสดงต่อสาธารณะ = ผู้ติดตามยังไม่เคยถูกแจ้ง
        if ($wasHidden) {
            try {
                require_once __DIR__ . '/../includes/FollowerFeedService.php';
                FollowerFeedService::notifyNewListing(
                    $conn,
                    $productBefore['seller_name'],
                    $productId,
                    $productBefore['product_name'],
                    (float) $productBefore['price']
                );
            } catch (Throwable $feedError) {
                error_log('product_fraud_queue: follower feed failed — ' . $feedError->getMessage());
            }
        }

        echo "<script>alert('✅ อนุมัติประกาศแล้ว — แสดงต่อสาธารณะได้'); window.location='product_fraud_queue.php';</script>";
        exit;
    }

    if ($action === 'reject') {
        $productModel->updateFraudStatus($productId, 'rejected', 100, ['ปฏิเสธโดยแอดมิน']);
        echo "<script>alert('❌ ปฏิเสธประกาศแล้ว — ซ่อนจากหน้าร้าน'); window.location='product_fraud_queue.php';</script>";
        exit;
    }
}

$queue = $productModel->getFraudReviewQueue(100);
$page_title = 'ตรวจรูปสินค้า (AI)';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>
    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>🖼️ คิวตรวจสอบรูปสินค้า</h1>
                <p>Tier 1 ตรวจ EXIF ฟรี → Tier 2 ส่งเฉพาะภาพน่าสงสัยไป Google Cloud Vision</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>สินค้า</th>
                            <th>ผู้ขาย</th>
                            <th>คะแนนความเสี่ยง</th>
                            <th>สถานะ</th>
                            <th>เหตุผลจาก AI</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($queue && $queue->num_rows > 0): while ($row = $queue->fetch_assoc()):
                        $status = $row['image_fraud_status'] ?? 'pending';
                        $badgeClass = $status === 'flagged' ? 'admin-badge-flagged' : ($status === 'rejected' ? 'admin-badge-rejected' : 'admin-badge-pending');
                        $reasons = [];
                        if (!empty($row['image_fraud_reasons'])) {
                            $decoded = json_decode($row['image_fraud_reasons'], true);
                            if (is_array($decoded)) {
                                $reasons = $decoded;
                            }
                        }
                        $imgUrl = Product::imageUrl($row['image_path'] ?? '');
                    ?>
                        <tr>
                            <td>
                                <div class="admin-product-cell">
                                    <img class="admin-thumb" src="<?php echo htmlspecialchars($imgUrl); ?>" alt="">
                                    <div>
                                        <strong><?php echo safeText($row['product_name']); ?></strong><br>
                                        <span class="admin-text-muted">#<?php echo (int)$row['id']; ?> · ฿<?php echo number_format((float)$row['price'], 0); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo safeText($row['seller_name']); ?></td>
                            <td><?php echo $row['image_fraud_score'] !== null ? number_format((float)$row['image_fraud_score'], 0) . '/100' : '-'; ?></td>
                            <td><span class="admin-badge <?php echo $badgeClass; ?>"><?php echo safeText($status); ?></span></td>
                            <td>
                                <?php if ($reasons): ?>
                                    <ul class="admin-reasons">
                                        <?php foreach ($reasons as $reason): ?>
                                            <li><?php echo safeText($reason); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <span class="admin-text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-action-group">
                                    <form method="post" onsubmit="return confirm('อนุมัติประกาศนี้?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="admin-btn admin-btn-success admin-btn-sm">อนุมัติ</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('ปฏิเสธและซ่อนประกาศนี้?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="admin-btn admin-btn-danger admin-btn-sm">ปฏิเสธ</button>
                                    </form>
                                    <a href="../product.php?id=<?php echo (int)$row['id']; ?>" target="_blank" class="admin-text-muted">ดูประกาศ →</a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="6" class="empty">ไม่มีรายการรอตรวจสอบ 🎉</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>
