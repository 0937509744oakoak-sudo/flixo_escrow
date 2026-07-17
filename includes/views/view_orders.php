<?php

/** Orders tab — compact row layout */
require_once __DIR__ . '/../order_helper.php';

$res_buy = $orderModel->getOrdersByRole($me, 'buyer');
$res_sell = $orderModel->getOrdersByRole($me, 'seller');

$buy_orders = [];
while ($row = $res_buy->fetch_assoc()) {
    $buy_orders[] = $row;
}

$sell_orders = [];
while ($row = $res_sell->fetch_assoc()) {
    $sell_orders[] = $row;
}

$completed_order_ids = [];
foreach ($buy_orders as $row) {
    if ($row['status'] === OrderStateMachine::COMPLETED) {
        $completed_order_ids[] = (int) $row['id'];
    }
}
$reviewed_order_ids = isset($reviewModel)
    ? $reviewModel->getReviewedOrderIds($me, $completed_order_ids)
    : [];

function flixoOrderStatusClass($status) {
    switch ($status) {
        case OrderStateMachine::COMPLETED:
            return 'pill-success';
        case OrderStateMachine::CANCELLED:
            return 'pill-muted';
        case OrderStateMachine::DISPUTE:
            return 'pill-warning';
        case OrderStateMachine::PENDING_PAYMENT:
            return 'pill-alert';
        default:
            return 'pill-progress';
    }
}

function flixoOrderNeedsAction($row, $role, $reviewed_ids) {
    $id = (int) $row['id'];
    $st = $row['status'];
    if ($role === 'buy') {
        if ($st === OrderStateMachine::PENDING_PAYMENT) return true;
        if ($st === OrderStateMachine::SHIPPED) return true;
        if ($st === OrderStateMachine::COMPLETED && empty($reviewed_ids[$id])) return true;
    } else {
        if ($st === OrderStateMachine::PAID) return true;
    }
    return false;
}

function flixoOrderNextStep($row, $role, $reviewed_ids) {
    $id = (int) $row['id'];
    $st = $row['status'];
    if ($role === 'buy') {
        if ($st === OrderStateMachine::PENDING_PAYMENT) return 'ชำระเงินเพื่อเริ่ม Escrow';
        if ($st === OrderStateMachine::WAITING_VERIFY) return 'รอระบบตรวจสอบสลิป';
        if ($st === OrderStateMachine::PAID) return 'รอผู้ขายจัดส่ง';
        if ($st === OrderStateMachine::SHIPPED) return 'ได้รับของแล้ว? ตรวจสอบแล้วกดยืนยัน';
        if ($st === OrderStateMachine::COMPLETED && empty($reviewed_ids[$id])) return 'ช่วยรีวิวผู้ขายหน่อย';
    } else {
        if ($st === OrderStateMachine::PAID) return 'ผู้ซื้อจ่ายแล้ว — จัดส่งและแจ้งเลขพัสดุ';
        if ($st === OrderStateMachine::WAITING_VERIFY) return 'รอแอดมินยืนยันยอดโอน';
        if ($st === OrderStateMachine::SHIPPED) return 'รอผู้ซื้อยืนยันรับของ';
    }
    if ($st === OrderStateMachine::DISPUTE) return 'อยู่ระหว่างข้อพิพาท';
    if ($st === OrderStateMachine::CREATED) return 'ข้อเสนอรอการยืนยันในห้องแชท';
    return '';
}

$all_orders = [];
foreach ($buy_orders as $row) {
    $all_orders[] = ['role' => 'buy', 'row' => $row];
}
foreach ($sell_orders as $row) {
    $all_orders[] = ['role' => 'sell', 'row' => $row];
}

foreach ($all_orders as $i => $item) {
    $all_orders[$i]['action'] = flixoOrderNeedsAction($item['row'], $item['role'], $reviewed_order_ids);
}

// Action-needed orders float to the top, then newest first
usort($all_orders, function ($a, $b) {
    if ($a['action'] !== $b['action']) {
        return $b['action'] <=> $a['action'];
    }
    return ((int) $b['row']['id']) <=> ((int) $a['row']['id']);
});

$action_count = 0;
foreach ($all_orders as $item) {
    if ($item['action']) $action_count++;
}
?>

<div class="flixo-card orders-tab-card">

    <div class="orders-tab-header">
        <div>
            <h3>ออเดอร์ของฉัน</h3>
            <p class="tx-meta">รายการที่ต้องทำจะอยู่บนสุดเสมอ</p>
        </div>
        <?php if ($action_count > 0): ?>
            <span class="orders-action-badge"><?php echo (int) $action_count; ?> รายการต้องทำ</span>
        <?php endif; ?>
    </div>

    <div class="orders-filter-bar" role="tablist" aria-label="กรองออเดอร์">
        <button type="button" class="orders-filter-chip" data-orders-filter="all">ทั้งหมด</button>
        <button type="button" class="orders-filter-chip" data-orders-filter="action">ต้องทำ<?php if ($action_count > 0) echo ' (' . (int) $action_count . ')'; ?></button>
        <button type="button" class="orders-filter-chip" data-orders-filter="buy">ฉันซื้อ</button>
        <button type="button" class="orders-filter-chip" data-orders-filter="sell">ฉันขาย</button>
    </div>

    <div id="orders-list">
        <?php if (count($all_orders) === 0): ?>
            <div class="empty-state-box">
                <span class="material-symbols-outlined">receipt_long</span>
                <p>ยังไม่มีออเดอร์</p>
                <a href="dashboard.php?tab=home" class="btn-flixo-primary orders-empty-cta" data-app-tab="home">ไปดูประกาศขาย</a>
            </div>
        <?php else: ?>
            <?php foreach ($all_orders as $item):
                $row = $item['row'];
                $role = $item['role'];
                $order_id = (int) $row['id'];
                $needs_action = $item['action'];
                $title = htmlspecialchars($row['product_name'] ?? $row['item_name'] ?? 'ออเดอร์');
                $st = $row['status'];
                $is_terminal = ($st === OrderStateMachine::COMPLETED || $st === OrderStateMachine::CANCELLED);
                $counterparty = $role === 'buy'
                    ? userDisplayName($conn, $row['seller_name'])
                    : userDisplayName($conn, $row['buyer_name']);
                $chat_user = $role === 'buy' ? $row['seller_name'] : $row['buyer_name'];
                $hint = flixoOrderNextStep($row, $role, $reviewed_order_ids);
                $thumb = Product::imageUrl($row['image_path'] ?? '');

                $date_txt = '';
                if (!empty($row['created_at']) && ($order_ts = strtotime($row['created_at']))) {
                    $date_txt = date('j', $order_ts) . ' ' . $thai_months[(int) date('n', $order_ts)] . ' ' . date('Y', $order_ts);
                }
            ?>
            <article class="order-row<?php echo $needs_action ? ' is-action' : ''; ?><?php echo $is_terminal ? ' is-done' : ''; ?>"
                     data-role="<?php echo $role; ?>"
                     data-action="<?php echo $needs_action ? '1' : '0'; ?>">

                <div class="order-row-main">
                    <img class="order-row-thumb"
                         src="<?php echo htmlspecialchars($thumb); ?>"
                         alt=""
                         loading="lazy"
                         onerror='this.onerror=null;this.src=<?php echo json_encode(Product::placeholderUrl()); ?>'>

                    <div class="order-row-info">
                        <div class="order-row-topline">
                            <span class="order-row-role order-row-role--<?php echo $role; ?>"><?php echo $role === 'buy' ? 'ฉันซื้อ' : 'ฉันขาย'; ?></span>
                            <span class="order-row-sub">#<?php echo $order_id; ?><?php if ($date_txt !== '') echo ' • ' . $date_txt; ?></span>
                        </div>
                        <div class="order-row-title"><?php echo $title; ?></div>
                        <div class="order-row-meta">
                            <?php echo $role === 'buy' ? 'ผู้ขาย' : 'ผู้ซื้อ'; ?>: <?php echo htmlspecialchars($counterparty); ?>
                        </div>
                    </div>

                    <div class="order-row-side">
                        <div class="order-row-amount">฿<?php echo number_format((float) $row['amount'], 2); ?></div>
                        <span class="<?php echo flixoOrderStatusClass($st); ?>"><?php echo htmlspecialchars(OrderStateMachine::getLabel($st)); ?></span>
                    </div>
                </div>

                <div class="order-row-foot">
                    <?php if ($hint !== ''): ?>
                        <div class="order-row-hint<?php echo $needs_action ? ' order-row-hint--action' : ''; ?>">
                            <span class="material-symbols-outlined" aria-hidden="true"><?php echo $needs_action ? 'arrow_forward' : 'hourglass_empty'; ?></span>
                            <?php echo htmlspecialchars($hint); ?>
                            <?php if ($role === 'sell' && $st === OrderStateMachine::SHIPPED && !empty($row['tracking_number'])): ?>
                                • <?php echo htmlspecialchars($row['tracking_number']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="order-row-btns">
                        <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($chat_user); ?>"
                           class="order-row-chat"
                           data-order-chat="<?php echo htmlspecialchars($chat_user, ENT_QUOTES, 'UTF-8'); ?>"
                           title="คุยกับ<?php echo htmlspecialchars($counterparty); ?>"
                           aria-label="แชท">
                            <span class="material-symbols-outlined" aria-hidden="true">chat</span>
                        </a>

                        <?php if ($role === 'buy' && $st === OrderStateMachine::PENDING_PAYMENT): ?>

                            <a href="buy_product.php?order_id=<?php echo $order_id; ?>" class="btn-flixo-primary order-row-btn">ชำระเงิน</a>

                        <?php elseif ($role === 'buy' && $st === OrderStateMachine::SHIPPED): ?>

                            <?php if (!empty($row['tracking_number'])): ?>
                                <button type="button" class="btn-flixo-outline order-row-btn" onclick="showTrackingTimeline(<?php echo $order_id; ?>)">ติดตามพัสดุ</button>
                            <?php endif; ?>
                            <button type="button" class="btn-flixo-outline order-row-btn order-row-btn-dispute" onclick="ordersOpenDispute(<?php echo $order_id; ?>)">เปิดข้อพิพาท</button>
                            <button type="button" class="btn-flixo-primary order-row-btn" onclick="ordersConfirmReceive(<?php echo $order_id; ?>)">ยืนยันรับของ</button>

                        <?php elseif ($role === 'buy' && $st === OrderStateMachine::COMPLETED && empty($reviewed_order_ids[$order_id])): ?>

                            <a href="write_review.php?order_id=<?php echo $order_id; ?>" class="btn-flixo-primary order-row-btn">เขียนรีวิว</a>

                        <?php elseif ($role === 'sell' && $st === OrderStateMachine::PAID): ?>

                            <button type="button" class="btn-flixo-primary order-row-btn" onclick="openTrackingModal(<?php echo $order_id; ?>)">แจ้งเลขพัสดุ</button>

                        <?php elseif ($st === OrderStateMachine::DISPUTE): ?>

                            <a href="my_disputes.php" class="btn-flixo-outline order-row-btn">ดูข้อพิพาท</a>

                        <?php elseif ($st === OrderStateMachine::WAITING_VERIFY): ?>

                            <?php $slipUrl = !empty($row['slip_image']) ? orderSlipUrl($row['slip_image'], $order_id) : ''; ?>
                            <?php if ($slipUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($slipUrl); ?>" target="_blank" rel="noopener" class="btn-flixo-outline order-row-btn"><?php echo $role === 'buy' ? 'ดูสลิปที่ส่ง' : 'ดูสลิปผู้ซื้อ'; ?></a>
                            <?php endif; ?>

                        <?php elseif ($role === 'sell' && $st === OrderStateMachine::SHIPPED && !empty($row['tracking_number'])): ?>

                            <button type="button" class="btn-flixo-outline order-row-btn" onclick="showTrackingTimeline(<?php echo $order_id; ?>)">ติดตามพัสดุ</button>

                        <?php endif; ?>
                    </div>
                </div>

            </article>
            <?php endforeach; ?>

            <div id="orders-filter-empty" class="empty-state-box" style="display:none;">
                <span class="material-symbols-outlined">filter_alt_off</span>
                <p>ไม่มีรายการในหมวดนี้</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
(function () {
    var chips = document.querySelectorAll('[data-orders-filter]');
    var items = document.querySelectorAll('.order-row');
    var empty = document.getElementById('orders-filter-empty');

    function applyFilter(filter) {
        var visible = 0;
        items.forEach(function (el) {
            var role = el.getAttribute('data-role');
            var action = el.getAttribute('data-action') === '1';
            var show = filter === 'all'
                || (filter === 'action' && action)
                || (filter === role);
            el.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (empty) empty.style.display = visible === 0 && items.length > 0 ? '' : 'none';
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (c) { c.classList.remove('active'); });
            chip.classList.add('active');
            applyFilter(chip.getAttribute('data-orders-filter'));
        });
    });

    var defaultFilter = 'all';
    chips.forEach(function (c) {
        c.classList.toggle('active', c.getAttribute('data-orders-filter') === defaultFilter);
    });
    applyFilter(defaultFilter);

    document.querySelectorAll('[data-order-chat]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var user = el.getAttribute('data-order-chat');
            if (window.flixoSwitchTab) {
                window.flixoSwitchTab('chat', { chatPartner: user });
            } else {
                window.location.href = 'dashboard.php?tab=chat&user=' + encodeURIComponent(user);
            }
        });
    });
})();

function ordersConfirmReceive(id) {
    Swal.fire({
        title: 'ยืนยันได้รับสินค้า?',
        text: 'ระบบจะโอนเงินให้ผู้ขาย',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'ได้รับของแล้ว',
        cancelButtonText: 'ยังไม่รับ'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        var fd = new FormData();
        fd.append('action', 'confirm_receive');
        fd.append('order_id', id);
        fetch('<?php echo BASE_URL; ?>/controllers/OrderController.php', { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    Swal.fire('สำเร็จ', 'โอนเงินให้ผู้ขายแล้ว', 'success').then(function () { location.reload(); });
                } else {
                    Swal.fire('ข้อผิดพลาด', data.message || 'ไม่สำเร็จ', 'error');
                }
            });
    });
}
</script>
