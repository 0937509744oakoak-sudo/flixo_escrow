<?php

/** Orders tab — simplified UX */
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



function flixoOrderStatusLabel($status) {

    return OrderStateMachine::getLabel($status);

}



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

        if ($st === OrderStateMachine::SHIPPED) return 'ตรวจสอบของแล้วกดยืนยัน';

        if ($st === OrderStateMachine::COMPLETED && empty($reviewed_ids[$id])) return 'ช่วยรีวิวผู้ขาย';

        if ($st === OrderStateMachine::COMPLETED) return 'ธุรกรรมสำเร็จแล้ว';

    } else {

        if ($st === OrderStateMachine::PAID) return 'แจ้งเลขพัสดุให้ผู้ซื้อ';

        if ($st === OrderStateMachine::SHIPPED) return 'รอผู้ซื้อยืนยันรับของ';

        if ($st === OrderStateMachine::COMPLETED) return 'ขายสำเร็จแล้ว';

    }

    if ($st === OrderStateMachine::DISPUTE) return 'อยู่ระหว่างข้อพิพาท';

    if ($st === OrderStateMachine::CANCELLED) return 'ออเดอร์ถูกยกเลิก';

    return flixoOrderStatusLabel($st);

}



$all_orders = [];

foreach ($buy_orders as $row) {

    $all_orders[] = ['role' => 'buy', 'row' => $row];

}

foreach ($sell_orders as $row) {

    $all_orders[] = ['role' => 'sell', 'row' => $row];

}

usort($all_orders, function ($a, $b) {

    return ((int) $b['row']['id']) <=> ((int) $a['row']['id']);

});



$action_count = 0;

foreach ($all_orders as $item) {

    if (flixoOrderNeedsAction($item['row'], $item['role'], $reviewed_order_ids)) {

        $action_count++;

    }

}

?>



<div class="flixo-card orders-tab-card">

    <div class="orders-tab-header">

        <div>

            <h3>ออเดอร์ของฉัน</h3>

            <p class="tx-meta">จัดการซื้อ-ขายและ Escrow ได้จากที่นี่</p>

        </div>

        <?php if ($action_count > 0): ?>

            <span class="orders-action-badge"><?php echo (int) $action_count; ?> ต้องทำ</span>

        <?php endif; ?>

    </div>



    <div class="orders-filter-bar" role="tablist" aria-label="กรองออเดอร์">

        <button type="button" class="orders-filter-chip<?php echo $action_count > 0 ? ' active' : ''; ?>" data-orders-filter="action">ต้องทำ<?php if ($action_count > 0) echo ' (' . (int) $action_count . ')'; ?></button>

        <button type="button" class="orders-filter-chip<?php echo $action_count === 0 ? ' active' : ''; ?>" data-orders-filter="all">ทั้งหมด</button>

        <button type="button" class="orders-filter-chip" data-orders-filter="buy">ซื้อ</button>

        <button type="button" class="orders-filter-chip" data-orders-filter="sell">ขาย</button>

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

                $needs_action = flixoOrderNeedsAction($row, $role, $reviewed_order_ids);

                $title = htmlspecialchars($row['product_name'] ?? $row['item_name'] ?? 'ออเดอร์');

                $st = $row['status'];

                $counterparty = $role === 'buy'

                    ? userDisplayName($conn, $row['seller_name'])

                    : userDisplayName($conn, $row['buyer_name']);

                $chat_user = $role === 'buy' ? $row['seller_name'] : $row['buyer_name'];

            ?>

            <article class="order-tab-item<?php echo $needs_action ? ' is-action' : ''; ?>"

                     data-role="<?php echo $role; ?>"

                     data-action="<?php echo $needs_action ? '1' : '0'; ?>">

                <div class="order-tab-item-top">

                    <span class="order-tab-role order-tab-role--<?php echo $role; ?>"><?php echo $role === 'buy' ? 'ซื้อ' : 'ขาย'; ?></span>

                    <span class="<?php echo flixoOrderStatusClass($st); ?>"><?php echo htmlspecialchars(flixoOrderStatusLabel($st)); ?></span>

                </div>

                <div class="order-tab-item-main">

                    <div>

                        <div class="order-tab-title"><?php echo $title; ?></div>

                        <div class="order-tab-meta">

                            <?php echo $role === 'buy' ? 'ผู้ขาย' : 'ผู้ซื้อ'; ?>:

                            <?php echo htmlspecialchars($counterparty); ?>

                            • #<?php echo $order_id; ?>

                        </div>

                        <div class="order-tab-hint"><?php echo htmlspecialchars(flixoOrderNextStep($row, $role, $reviewed_order_ids)); ?></div>

                    </div>

                    <div class="order-tab-amount">฿<?php echo number_format((float) $row['amount'], 2); ?></div>

                </div>

                <div class="order-tab-actions">

                    <a href="dashboard.php?tab=chat&amp;user=<?php echo urlencode($chat_user); ?>"
                       class="btn-flixo-outline order-tab-btn order-tab-chat"
                       data-order-chat="<?php echo htmlspecialchars($chat_user, ENT_QUOTES, 'UTF-8'); ?>"
                       title="คุยกับ<?php echo htmlspecialchars($counterparty); ?>">
                        <span class="material-symbols-outlined" aria-hidden="true">chat</span>
                        คุย
                    </a>

                    <?php if ($role === 'buy' && $st === OrderStateMachine::PENDING_PAYMENT): ?>

                        <a href="buy_product.php?order_id=<?php echo $order_id; ?>" class="btn-flixo-primary order-tab-btn">ชำระเงิน</a>

                    <?php elseif ($role === 'buy' && $st === OrderStateMachine::SHIPPED): ?>

                        <button type="button" class="btn-flixo-primary order-tab-btn" onclick="ordersConfirmReceive(<?php echo $order_id; ?>)">ยืนยันรับของ</button>
                        <button type="button" class="btn-flixo-outline order-tab-btn order-tab-btn-dispute" onclick="ordersOpenDispute(<?php echo $order_id; ?>)">เปิดข้อพิพาท</button>

                    <?php elseif ($role === 'buy' && $st === OrderStateMachine::COMPLETED && empty($reviewed_order_ids[$order_id])): ?>

                        <a href="write_review.php?order_id=<?php echo $order_id; ?>" class="btn-flixo-outline order-tab-btn">เขียนรีวิว</a>

                    <?php elseif ($role === 'sell' && $st === OrderStateMachine::PAID): ?>

                        <button type="button" class="btn-flixo-primary order-tab-btn" onclick="openTrackingModal(<?php echo $order_id; ?>)">แจ้งเลขพัสดุ</button>

                    <?php elseif ($st === OrderStateMachine::DISPUTE): ?>

                        <a href="my_disputes.php" class="btn-flixo-outline order-tab-btn">ดูข้อพิพาท</a>

                    <?php elseif ($role === 'buy' && $st === OrderStateMachine::WAITING_VERIFY): ?>
                        <?php $slipUrl = !empty($row['slip_image']) ? orderSlipUrl($row['slip_image'], $order_id) : ''; ?>
                        <?php if ($slipUrl !== ''): ?>
                            <a href="<?php echo htmlspecialchars($slipUrl); ?>" target="_blank" rel="noopener" class="btn-flixo-outline order-tab-btn">ดูสลิปที่ส่ง</a>
                        <?php endif; ?>
                        <span class="order-tab-wait tx-meta">รอตรวจสอบสลิป</span>

                    <?php elseif ($role === 'buy' && $st === OrderStateMachine::PAID): ?>

                        <span class="order-tab-wait tx-meta">รอดำเนินการจากอีกฝ่าย</span>

                    <?php elseif ($role === 'sell' && $st === OrderStateMachine::WAITING_VERIFY): ?>
                        <?php $slipUrl = !empty($row['slip_image']) ? orderSlipUrl($row['slip_image'], $order_id) : ''; ?>
                        <?php if ($slipUrl !== ''): ?>
                            <a href="<?php echo htmlspecialchars($slipUrl); ?>" target="_blank" rel="noopener" class="btn-flixo-outline order-tab-btn">ดูสลิปผู้ซื้อ</a>
                        <?php endif; ?>
                        <span class="order-tab-wait tx-meta">รอแอดมินยืนยันยอด</span>

                    <?php elseif ($role === 'sell' && $st === OrderStateMachine::SHIPPED): ?>

                        <span class="order-tab-wait tx-meta">รอผู้ซื้อยืนยัน<?php if (!empty($row['tracking_number'])) echo ' • ' . htmlspecialchars($row['tracking_number']); ?></span>

                    <?php elseif ($st === OrderStateMachine::COMPLETED): ?>

                        <span class="order-tab-done tx-meta">สำเร็จ ✓</span>

                    <?php endif; ?>

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

    var items = document.querySelectorAll('.order-tab-item');

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



    var defaultFilter = <?php echo $action_count > 0 ? "'action'" : "'all'"; ?>;

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


