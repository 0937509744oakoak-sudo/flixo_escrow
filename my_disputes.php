<?php

// my_disputes.php - ดูรายการข้อพิพาทของผู้ใช้
require_once 'includes/init.php';
require_once 'includes/auth.php';
require_once 'models/Dispute.php';

$disputeModel = new Dispute($conn);
$page = (int)($_GET['page'] ?? 1);
$limit = 10;
$offset = ($page - 1) * $limit;

$disputes = $disputeModel->getUserDisputes($_SESSION['user'], $limit, $offset);

$csrf_token = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อพิพาทของฉัน | FLIXO</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .dispute-card { background: var(--card-bg); padding: 15px; border-radius: 8px; margin-bottom: 10px; border-left: 4px solid var(--primary-color); display: flex; justify-content: space-between; align-items: center; }
        .dispute-status { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 12px; font-weight: bold; }
        .status-pending { background: #f39c12; color: white; }
        .status-resolved { background: #27ae60; color: white; }
    </style>
</head>
<body>
<?php include 'includes/header.php'; include 'includes/navbar.php'; ?>

<div style="max-width: 900px; margin: 30px auto; padding: 0 15px;">
    <h1>📋 ข้อพิพาทของฉัน</h1>

    <?php if ($disputes && $disputes->num_rows > 0): ?>
        <?php while ($dispute = $disputes->fetch_assoc()): ?>
            <div class="dispute-card">
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 5px 0; font-size: 16px;">
                        #<?php echo $dispute['order_id']; ?> - <?php echo htmlspecialchars($dispute['item_name']); ?>
                    </h3>
                    <p style="margin: 5px 0; font-size: 12px; color: #666;">
                        <strong><?php echo htmlspecialchars($dispute['reason']); ?></strong>
                    </p>
                    <p style="margin: 5px 0; font-size: 12px; color: #999;">
                        เปิดเมื่อ: <?php echo date('d/m/Y H:i', strtotime($dispute['created_at'])); ?>
                        <?php if ($dispute['status'] === 'RESOLVED'): ?>
                            | แก้ไขเมื่อ: <?php echo date('d/m/Y H:i', strtotime($dispute['resolved_at'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="text-align: right;">
                    <span class="dispute-status <?php echo $dispute['status'] === 'PENDING' ? 'status-pending' : 'status-resolved'; ?>">
                        <?php echo $dispute['status'] === 'PENDING' ? '⏳ รอแก้ไข' : '✅ ' . ($dispute['resolution'] === 'REFUND_BUYER' ? 'คืนเงิน' : 'โอนให้ผู้ขาย'); ?>
                    </span>
                    <br>
                    <a href="dispute_detail.php?dispute_id=<?php echo $dispute['id']; ?>" class="btn btn-primary" style="margin-top: 10px; padding: 8px 15px; font-size: 12px;">
                        ดูรายละเอียด
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 40px; background: var(--card-bg); border-radius: 12px;">
            <p style="font-size: 48px;">😊</p>
            <p style="color: #666;">ยังไม่มีข้อพิพาท</p>
            <a href="dashboard.php?tab=orders" class="btn btn-primary" style="margin-top: 15px;">ดูออเดอร์ของฉัน</a>
        </div>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 30px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>" class="btn">← ก่อนหน้า</a>
        <?php endif; ?>
        <?php if ($disputes && $disputes->num_rows === $limit): ?>
            <a href="?page=<?php echo $page + 1; ?>" class="btn">ถัดไป →</a>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
</body>
</html>
