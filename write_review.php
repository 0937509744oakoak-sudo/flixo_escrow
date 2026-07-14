<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/OrderStateMachine.php';
require_once __DIR__ . '/models/Review.php';

if (!isset($_SESSION['user']) || !isset($_GET['order_id'])) {
    redirectTo('dashboard.php');
}

$order_id = (int)$_GET['order_id'];
$me = $_SESSION['user'];
$reviewModel = new Review($conn);

$sql = "SELECT * FROM orders WHERE id = ? AND buyer_name = ? AND status = '" . OrderStateMachine::COMPLETED . "'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $order_id, $me);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo "<script>alert('ไม่สามารถรีวิวได้'); window.location='dashboard.php?tab=orders';</script>";
    exit();
}

if ($reviewModel->existsForOrder($order_id)) {
    echo "<script>alert('คุณรีวิวออเดอร์นี้ไปแล้ว'); window.location='dashboard.php?tab=orders';</script>";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!csrfTokenIsValid($_POST['csrf_token'] ?? '')) {
        $error = 'เซสชันหมดอายุหรือโทเคนไม่ถูกต้อง — กรุณารีเฟรชหน้าแล้วลองใหม่';
    } else {
        $rating = filter_var($_POST['rating'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5]
        ]);
        $comment = trim($_POST['comment'] ?? '');
        $target_name = $order['seller_name'];

        if ($rating === false || $comment === '') {
            $error = 'กรุณาเลือกดาวและเขียนความเห็นอย่างน้อยหนึ่งบรรทัด';
        } elseif ($reviewModel->create($me, $target_name, $rating, str_replace("\0", '', $comment), $order_id)) {
            echo "<script>
                alert('ขอบคุณสำหรับรีวิว! 🎉');
                window.location = 'dashboard.php?tab=orders';
            </script>";
            exit();
        } else {
            $error = $reviewModel->existsForOrder($order_id)
                ? 'คุณรีวิวออเดอร์นี้ไปแล้ว'
                : 'ไม่สามารถบันทึกรีวิวได้ กรุณาลองใหม่';
        }
    }
}

$page_title = "เขียนรีวิว";
include 'includes/header.php';
include 'includes/navbar.php';
?>

<style>
    .review-container { max-width: 500px; margin: 50px auto; padding: 30px; background: var(--card-bg); border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid var(--border-color); text-align: center; }
    .review-stars { display: flex; justify-content: center; gap: 6px; margin: 20px 0 8px; direction: rtl; }
    .review-stars button {
        border: none; background: none; font-size: 40px; line-height: 1; color: #ddd;
        cursor: pointer; padding: 4px; transition: color 0.15s, transform 0.15s;
    }
    .review-stars button:hover,
    .review-stars button.is-active { color: #ffc107; transform: scale(1.08); }
    .review-stars-hint { color: #64748b; font-size: 13px; min-height: 20px; margin-bottom: 12px; }
    textarea { width: 100%; padding: 15px; border: 1px solid var(--border-color); border-radius: 10px; background: var(--bg-color); color: var(--text-color); margin-bottom: 20px; box-sizing: border-box; font-family: inherit; }
    .btn-submit { background: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 50px; font-size: 16px; cursor: pointer; font-weight: bold; width: 100%; }
    .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
    .error-box { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; text-align: left; font-size: 14px; }
</style>

<div class="review-container">
    <h2>✍️ เขียนรีวิว</h2>
    <?php if (!empty($error)): ?>
        <div class="error-box"><?php echo safeText($error); ?></div>
    <?php endif; ?>
    <p>สินค้า: <strong><?php echo htmlspecialchars($order['item_name']); ?></strong></p>
    <p>ผู้ขาย: @<?php echo htmlspecialchars($order['seller_name']); ?></p>

    <form method="POST" id="review-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo safeText(getCsrfToken()); ?>">
        <input type="hidden" name="rating" id="review-rating" value="<?php echo safeText($_POST['rating'] ?? ''); ?>">

        <div class="review-stars" id="review-stars" role="group" aria-label="ให้คะแนน">
            <?php for ($s = 5; $s >= 1; $s--): ?>
                <button type="button" class="review-star-btn" data-value="<?php echo $s; ?>" aria-label="<?php echo $s; ?> ดาว">★</button>
            <?php endfor; ?>
        </div>
        <div class="review-stars-hint" id="review-stars-hint">แตะดาวเพื่อให้คะแนน</div>

        <textarea name="comment" id="review-comment" rows="4" placeholder="บอกเล่าประสบการณ์การซื้อขาย..." maxlength="1000"><?php echo safeText($_POST['comment'] ?? ''); ?></textarea>
        
        <button type="submit" class="btn-submit" id="review-submit">ส่งรีวิว</button>
    </form>
</div>
<script>
(function () {
    const form = document.getElementById('review-form');
    const ratingInput = document.getElementById('review-rating');
    const hint = document.getElementById('review-stars-hint');
    const stars = Array.from(document.querySelectorAll('.review-star-btn'));
    const labels = { 1: '1 ดาว — ไม่พอใจ', 2: '2 ดาว', 3: '3 ดาว — พอใช้', 4: '4 ดาว — ดี', 5: '5 ดาว — ยอดเยี่ยม' };

    function paintStars(value) {
        stars.forEach(function (btn) {
            btn.classList.toggle('is-active', Number(btn.dataset.value) <= value);
        });
        hint.textContent = value ? (labels[value] || (value + ' ดาว')) : 'แตะดาวเพื่อให้คะแนน';
    }

    stars.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const v = Number(btn.dataset.value);
            ratingInput.value = String(v);
            paintStars(v);
        });
    });

    const initial = Number(ratingInput.value || 0);
    if (initial >= 1 && initial <= 5) {
        paintStars(initial);
    }

    form.addEventListener('submit', function (e) {
        const rating = Number(ratingInput.value || 0);
        const comment = (document.getElementById('review-comment').value || '').trim();
        if (rating < 1 || rating > 5) {
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire('กรุณาให้คะแนน', 'แตะดาว 1–5 ก่อนส่งรีวิว', 'warning');
            } else {
                alert('กรุณาแตะดาวให้คะแนนก่อนส่งรีวิว');
            }
            return;
        }
        if (!comment) {
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire('กรุณาเขียนรีวิว', 'บอกเล่าประสบการณ์สั้น ๆ อย่างน้อยหนึ่งบรรทัด', 'warning');
            } else {
                alert('กรุณาเขียนความเห็นก่อนส่งรีวิว');
            }
        }
    });
})();
</script>
<?php include 'includes/footer.php'; ?>
