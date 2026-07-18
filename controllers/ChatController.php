<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../includes/CourierService.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/ChatModerationService.php';
require_once __DIR__ . '/../includes/chat_helpers.php';
require_once __DIR__ . '/../includes/chat_realtime.php';
verifyCsrfToken($_POST['csrf_token'] ?? ''); 

header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(403); exit('<div class="error">Unauthorized</div>');
}

$action = $_POST['action'] ?? '';
$me = $_SESSION['user'];


function renderOfferCard(array $ord, string $viewerUsername) {
    $oid = (int)$ord['id'];
    $item_name = htmlspecialchars($ord['item_name']);
    $amount = (float)$ord['amount'];
    $status = $ord['status'];
    $tracking = htmlspecialchars($ord['tracking_number'] ?? '');
    $courier = htmlspecialchars($ord['courier'] ?? '');
    $is_buyer = ($viewerUsername === $ord['buyer_name']);
    $is_seller = ($viewerUsername === $ord['seller_name']);

    $stepMap = [
        OrderStateMachine::CREATED         => 0,
        OrderStateMachine::PENDING_PAYMENT => 1,
        OrderStateMachine::WAITING_VERIFY  => 1,
        OrderStateMachine::PAID            => 2,
        OrderStateMachine::SHIPPED         => 2,
        OrderStateMachine::COMPLETED       => 3,
    ];
    $steps = ['ตกลงดีล', 'ชำระเงิน', 'จัดส่ง', 'สำเร็จ'];

    $html = '<div class="offer-card">';
    $html .= '<div class="offer-card-top">';
    $html .= '<span class="offer-card-tag"><span class="material-symbols-outlined">verified_user</span>ดีลผ่าน Escrow</span>';
    $html .= '<span class="offer-card-id">#' . $oid . '</span>';
    $html .= '</div>';
    $html .= '<div class="offer-body">';
    $html .= '<div class="offer-card-name">' . $item_name . '</div>';
    $html .= '<div class="offer-price">฿' . number_format($amount, 2) . '</div>';

    if (isset($stepMap[$status])) {
        $cur = $stepMap[$status];
        $done_all = ($status === OrderStateMachine::COMPLETED);
        $html .= '<div class="offer-steps">';
        foreach ($steps as $i => $label) {
            if ($done_all || $i < $cur) {
                $cls = ' is-done';
            } elseif ($i === $cur) {
                $cls = ' is-current';
            } else {
                $cls = '';
            }
            $html .= '<div class="offer-step' . $cls . '"><span class="offer-step-dot"></span><span class="offer-step-label">' . $label . '</span></div>';
        }
        $html .= '</div>';
    }

    $html .= '<div class="offer-card-actions">';

    switch ($status) {
        case OrderStateMachine::CREATED:
            if ($is_buyer) {
                $html .= '<button onclick="updateOrderStatus(' . $oid . ', \'confirm_offer\')" class="btn-action btn-blue">ยืนยันรับดีล</button>';
            } elseif ($is_seller) {
                $html .= '<div class="offer-note offer-note--wait">รอผู้ซื้อยืนยันดีล</div>';
            }
            break;

        case OrderStateMachine::PENDING_PAYMENT:
            if ($is_buyer) {
                $html .= '<a href="buy_product.php?order_id=' . $oid . '" class="btn-action btn-green">ชำระเงินผ่าน Escrow</a>';
            } elseif ($is_seller) {
                $html .= '<div class="offer-note offer-note--wait">รอผู้ซื้อชำระเงิน</div>';
            }
            break;

        case OrderStateMachine::WAITING_VERIFY:
            $html .= '<div class="offer-note offer-note--info">กำลังตรวจสอบสลิปโอนเงิน</div>';
            $slipUrl = !empty($ord['slip_image']) ? orderSlipUrl($ord['slip_image'], $oid) : '';
            if ($slipUrl !== '') {
                $safeSlip = htmlspecialchars($slipUrl, ENT_QUOTES, 'UTF-8');
                $html .= '<div class="offer-slip-box">';
                $html .= '<div class="offer-slip-label">หลักฐานสลิปที่ผู้ซื้ออัปโหลด</div>';
                $html .= '<a href="' . $safeSlip . '" target="_blank" rel="noopener"><img src="' . $safeSlip . '" alt="สลิปโอนเงิน"></a>';
                $html .= '</div>';
            }
            $sessionRole = strtolower((string) ($_SESSION['role'] ?? 'user'));
            if (in_array($sessionRole, ['admin', 'owner', 'finance'], true)) {
                $html .= '<a href="admin/escrow_list.php" class="btn-action btn-orange">ไปหน้าตรวจ Escrow (Admin)</a>';
            } elseif ($is_buyer) {
                $html .= '<div class="offer-note offer-note--wait">ระบบกำลังตรวจสอบสลิปของคุณ</div>';
            } elseif ($is_seller) {
                $html .= '<div class="offer-note offer-note--wait">รอแอดมินยืนยันยอดเงิน</div>';
            }
            break;

        case OrderStateMachine::PAID:
            $html .= '<div class="offer-note offer-note--success">ชำระเงินแล้ว — เงินพักใน Escrow</div>';
            if ($is_seller) {
                $html .= '<button onclick="openTrackingModal(' . $oid . ')" class="btn-action btn-blue">แจ้งเลขพัสดุ</button>';
            } elseif ($is_buyer) {
                $html .= '<div class="offer-note offer-note--wait">ผู้ขายกำลังจัดส่ง</div>';
            }
            break;

        case OrderStateMachine::SHIPPED:
            $courier_show = !empty($courier) ? $courier : 'ขนส่งเอกชน';
            $html .= '<div class="offer-ship-info">';
            $html .= '<span class="material-symbols-outlined">local_shipping</span>';
            $html .= '<div><strong>' . $courier_show . '</strong><span>' . $tracking . '</span></div>';
            $html .= '</div>';
            $html .= '<button type="button" onclick="showTrackingTimeline(' . $oid . ')" class="btn-action btn-ghost">ติดตามพัสดุ</button>';
            if ($is_buyer) {
                $html .= '<button onclick="updateOrderStatus(' . $oid . ', \'complete_order\')" class="btn-action btn-green">ได้รับสินค้าแล้ว</button>';
                $html .= '<button type="button" onclick="chatOpenDispute(' . $oid . ')" class="btn-action btn-danger-ghost">เปิดข้อพิพาท</button>';
            } elseif ($is_seller) {
                $html .= '<div class="offer-note offer-note--wait">รอผู้ซื้อตรวจสอบสินค้า</div>';
            }
            break;

        case OrderStateMachine::DISPUTE:
            $html .= '<div class="offer-note offer-note--danger">อยู่ระหว่างข้อพิพาท — เงินถูกระงับชั่วคราว</div>';
            $disputeLink = 'my_disputes.php';
            global $conn;
            $disStmt = $conn->prepare("SELECT id FROM disputes WHERE order_id = ? LIMIT 1");
            if ($disStmt) {
                $disStmt->bind_param('i', $oid);
                $disStmt->execute();
                $disRow = $disStmt->get_result()->fetch_assoc();
                if ($disRow) {
                    $disputeLink = 'dispute_detail.php?dispute_id=' . (int)$disRow['id'];
                }
            }
            $html .= '<a href="' . $disputeLink . '" class="btn-action btn-ghost">ดูข้อพิพาท</a>';
            break;

        case OrderStateMachine::COMPLETED:
            $html .= '<div class="offer-note offer-note--success">การซื้อขายสำเร็จ — โอนเงินให้ผู้ขายแล้ว</div>';
            break;

        case OrderStateMachine::CANCELLED:
            $html .= '<div class="offer-note offer-note--muted">ดีลนี้ถูกยกเลิกแล้ว</div>';
            break;
    }

    $html .= '</div></div></div>';
    return $html;
}

function renderProductCard($product, $is_me) {
    $pid = (int) $product['id'];
    $name = htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8');
    $price = number_format((float) $product['price']);
    $img = htmlspecialchars(Product::primaryImageUrl($product), ENT_QUOTES, 'UTF-8');
    $url = 'product.php?id=' . $pid;

    $html = '<div class="chat-product-card">';
    $html .= '<div class="chat-product-card-img"><img src="' . $img . '" alt="' . $name . '"></div>';
    $html .= '<div class="chat-product-card-body">';
    $html .= '<div class="chat-product-card-badge">ประกาศขาย</div>';
    $html .= '<div class="chat-product-card-name">' . $name . '</div>';
    $html .= '<div class="chat-product-card-price">฿' . $price . '</div>';
    if (!$is_me) {
        $html .= '<a href="' . $url . '" class="btn-action btn-green">ดูประกาศ &amp; ซื้อผ่าน Escrow</a>';
    } else {
        $html .= '<span class="status-wait">ส่งประกาศให้อีกฝ่ายแล้ว</span>';
    }
    $html .= '</div></div>';
    return $html;
}

// ดึงข้อความในห้องแชท
if ($action == 'fetch') {
    $partner = trim($_POST['partner'] ?? '');
    $stmt = $conn->prepare("SELECT * FROM messages WHERE (sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?) ORDER BY created_at ASC");
    $stmt->bind_param("ssss", $me, $partner, $partner, $me);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }

    if (count($rows) === 0) {
        echo '<div class="chat-start-state">';
        echo '<span class="material-symbols-outlined">waving_hand</span>';
        echo '<p>เริ่มต้นบทสนทนา — ทักทายอีกฝ่ายได้เลย</p>';
        echo '</div>';
    }

    $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $todayKey = date('Y-m-d');
    $yesterdayKey = date('Y-m-d', strtotime('-1 day'));
    $currentDateKey = '';
    $groupWindow = 300; // seconds — same sender within 5 min = one visual group

    foreach ($rows as $i => $row) {
        $is_me = ($row['sender_name'] == $me);
        $msg_text = $row['message'];
        $ts = !empty($row['created_at']) ? (strtotime($row['created_at']) ?: 0) : 0;
        $dateKey = $ts ? date('Y-m-d', $ts) : '';

        if ($dateKey !== '' && $dateKey !== $currentDateKey) {
            if ($dateKey === $todayKey) {
                $dateLabel = 'วันนี้';
            } elseif ($dateKey === $yesterdayKey) {
                $dateLabel = 'เมื่อวาน';
            } else {
                $dateLabel = date('j', $ts) . ' ' . $thaiMonths[(int) date('n', $ts)] . ' ' . date('Y', $ts);
            }
            echo '<div class="chat-date-sep"><span>' . $dateLabel . '</span></div>';
            $currentDateKey = $dateKey;
        }

        $prev = $rows[$i - 1] ?? null;
        $prevTs = ($prev && !empty($prev['created_at'])) ? (strtotime($prev['created_at']) ?: 0) : 0;
        $groupedWithPrev = $prev
            && $prev['sender_name'] === $row['sender_name']
            && $ts && $prevTs
            && ($ts - $prevTs) < $groupWindow
            && date('Y-m-d', $prevTs) === $dateKey;

        $next = $rows[$i + 1] ?? null;
        $nextTs = ($next && !empty($next['created_at'])) ? (strtotime($next['created_at']) ?: 0) : 0;
        $groupedWithNext = $next
            && $next['sender_name'] === $row['sender_name']
            && $ts && $nextTs
            && ($nextTs - $ts) < $groupWindow
            && date('Y-m-d', $nextTs) === $dateKey;

        $timeHtml = ($ts && !$groupedWithNext)
            ? '<div class="msg-time">' . date('H:i', $ts) . '</div>'
            : '';

        echo '<div class="msg-row ' . ($is_me ? 'msg-me' : 'msg-partner') . ($groupedWithPrev ? ' msg-row--grouped' : '') . '">';
        echo '<div class="msg-stack">';
        if (!empty($row['image_path'])) {
             $chatImg = htmlspecialchars(chatImageUrl($row['image_path']), ENT_QUOTES, 'UTF-8');
             echo '<div class="msg-bubble msg-bubble--image"><a href="'.$chatImg.'" target="_blank" rel="noopener"><img src="'.$chatImg.'" alt="รูปภาพในแชท" loading="lazy"></a></div>';
        } elseif (strpos($msg_text, '[PRODUCT:') !== false && preg_match('/\[PRODUCT:(\d+)\]/', $msg_text, $matches)) {
            $productModel = new Product($conn);
            $product = $productModel->getById((int) $matches[1]);
            if ($product) {
                echo renderProductCard($product, $is_me);
            }
        } elseif (strpos($msg_text, '[OFFER:') !== false && preg_match('/\[OFFER:(\d+)\]/', $msg_text, $matches)) {
            $oid = $matches[1];
            $ord_stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
            $ord_stmt->bind_param("i", $oid);
            $ord_stmt->execute();
            $ord = $ord_stmt->get_result()->fetch_assoc();
            if ($ord) {
                echo renderOfferCard($ord, $me);
            }
        } else {
            if (!empty($msg_text)) {
                require_once __DIR__ . '/../includes/support_chat.php';
                $isSupportMsg = isSupportUsername($row['sender_name']) || isSupportUsername($row['receiver_name']);
                if ($isSupportMsg && (strpos($msg_text, '[[flixo-btn:') !== false || strpos($msg_text, 'ดูรายละเอียด:') !== false)) {
                    echo '<div class="msg-bubble msg-bubble--actions">' . formatSupportChatMessageHtml($msg_text) . '</div>';
                } else {
                    echo '<div class="msg-bubble">' . nl2br(htmlspecialchars($msg_text, ENT_QUOTES, 'UTF-8')) . '</div>';
                }
            }
        }
        echo $timeHtml;
        echo '</div>';
        echo '</div>';
    }

    require_once __DIR__ . '/../includes/support_chat.php';
    markSupportMessagesRead($conn, $me, $partner);

    // เปิดห้องแชทแล้ว = อ่านแล้ว → เคลียร์แจ้งเตือนแชทจากคู่สนทนานี้
    flixo_mark_chat_notifications_read($conn, $me, $partner);
}


if ($action == 'send') {
    $partner = trim($_POST['partner'] ?? '');
    $msg = trim($_POST['message'] ?? '');
    
    if ($partner === '' || $msg === '') {
        http_response_code(400);
        echo 'กรุณากรอกข้อความและผู้รับ';
        exit();
    }

    $moderation = new ChatModerationService($conn);
    $modResult = null;
    if ($moderation->shouldScan($me, $partner, $msg)) {
        $modResult = $moderation->scanText($msg);
        if (
            $modResult['verdict'] === ChatModerationService::VERDICT_REJECT
            && defined('CHAT_MODERATION_BLOCK')
            && CHAT_MODERATION_BLOCK
        ) {
            $moderation->logScan(null, $me, $partner, 'text', $msg, $modResult);
            http_response_code(400);
            echo $moderation->formatUserMessage($modResult);
            exit();
        }
    }

    $stmt = $conn->prepare("INSERT INTO messages (sender_name, receiver_name, message) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $me, $partner, $msg);
    $stmt->execute();

    // Real-time push: signal SSE subscribers that a new message exists.
    // No-op if Redis is unavailable (SSE falls back to DB polling).
    chatRealtimePublish($me, $partner);

    if ($modResult !== null && $modResult['verdict'] !== ChatModerationService::VERDICT_PASS) {
        $messageId = (int)$conn->insert_id;
        $moderation->updateMessageModeration($messageId, $modResult);
        $moderation->logScan($messageId, $me, $partner, 'text', $msg, $modResult);
    }

    try {
        flixo_notify_chat_message($conn, $me, $partner, $msg);
    } catch (Throwable $notifyError) {
        error_log('ChatController send: notify failed — ' . $notifyError->getMessage());
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'moderation' => $modResult ? [
            'verdict' => $modResult['verdict'],
            'risk_score' => $modResult['risk_score'],
            'reasons' => $modResult['reasons'] ?? [],
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}


if ($action == 'fetch_my_products') {
    $productModel = new Product($conn);
    $result = $productModel->getBySeller($me, 50);

    if (!$result || $result->num_rows === 0) {
        echo '<div class="share-product-empty">'
            . '<span class="material-symbols-outlined">inventory_2</span>'
            . '<p>ยังไม่มีประกาศขาย</p>'
            . '<a href="dashboard.php?tab=sell" class="share-product-empty-link">ลงประกาศขายเลย</a>'
            . '</div>';
        exit();
    }

    $html = '<div class="share-product-grid">';
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id'];
        $name = htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8');
        $price = number_format((float) $row['price']);
        $img = htmlspecialchars(Product::imageUrl($row['image_path'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html .= '<button type="button" class="share-product-pick" onclick="sendShareProduct(' . $id . ')">'
            . '<img src="' . $img . '" alt="">'
            . '<span class="share-product-pick-name">' . $name . '</span>'
            . '<span class="share-product-pick-price">฿' . $price . '</span>'
            . '</button>';
    }
    $html .= '</div>';
    echo $html;
    exit();
}

if ($action == 'share_product') {
    $partner = trim($_POST['partner'] ?? '');
    $productId = (int) ($_POST['product_id'] ?? 0);

    if ($partner === '' || $productId <= 0) {
        http_response_code(400);
        echo 'ข้อมูลไม่ครบ';
        exit();
    }

    $productModel = new Product($conn);
    $product = $productModel->getById($productId);

    if (!$product || $product['seller_name'] !== $me) {
        http_response_code(403);
        echo 'ไม่พบสินค้าหรือไม่มีสิทธิ์แชร์';
        exit();
    }

    $code = "[PRODUCT:$productId]";
    $stmt = $conn->prepare("INSERT INTO messages (sender_name, receiver_name, message) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $me, $partner, $code);
    $stmt->execute();

    chatRealtimePublish($me, $partner);

    try {
        flixo_notify_chat_message($conn, $me, $partner, '📢 ส่งประกาศ "' . $product['product_name'] . '" ให้คุณ');
    } catch (Throwable $notifyError) {
        error_log('ChatController share_product: notify failed — ' . $notifyError->getMessage());
    }

    echo 'success';
    exit();
}


if ($action == 'create_offer') {
    $orderModel = new Order($conn);
    
    $partner = trim(filter_var($_POST['partner'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
    $item = trim(filter_var($_POST['item'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
    $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);

    if ($partner === '' || $item === '' || $price === false || $price <= 0) {
        echo "error";
        exit();
    }

    $itemImage = '';
    if (isset($_FILES['offer_image']) && ($_FILES['offer_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        try {
            $itemImage = secureUploadCloud(
                $_FILES['offer_image'],
                'products',
                flixoUploadsSubdir('products'),
                5242880,
                true
            );
        } catch (Throwable $uploadError) {
            error_log('ChatController create_offer: image upload failed — ' . $uploadError->getMessage());
            http_response_code(400);
            echo $uploadError->getMessage();
            exit();
        }
    }

    $oid = $orderModel->createOffer($partner, $me, $item, $price, 0, $itemImage);
    
    if ($oid) {
        $code = "[OFFER:$oid]";
        $msg_stmt = $conn->prepare("INSERT INTO messages (sender_name, receiver_name, message) VALUES (?, ?, ?)");
        $msg_stmt->bind_param("sss", $me, $partner, $code);
        $msg_stmt->execute();

        chatRealtimePublish($me, $partner);

        // มี Offer ใหม่ → แจ้งผู้ซื้อทันที (แยกจากแจ้งเตือนแชทปกติ ไม่ถูก throttle)
        try {
            createNotification(
                $conn,
                $partner,
                "🤝 " . userDisplayName($conn, $me) . " ส่งข้อเสนอ \"{$item}\" ราคา ฿" . number_format((float)$price, 2) . " (ออเดอร์ #$oid)",
                "buy_product.php?order_id=$oid"
            );
        } catch (Throwable $notifyError) {
            error_log('ChatController create_offer: notify failed — ' . $notifyError->getMessage());
        }

        echo "success";
    } else {
        echo "error";
    }
}


if ($action == 'send_image') {
    $partner = trim($_POST['partner'] ?? '');
    if ($partner === '') {
        http_response_code(400);
        echo 'error';
        exit();
    }

    try {
        if (isset($_FILES['chat_image']) && $_FILES['chat_image']['error'] == 0) {
            // ตรวจ moderation จากไฟล์ชั่วคราวก่อน (ยังไม่ต้องเก็บถาวร)
            // → ถ้าโดน block จะได้ไม่เสีย quota อัปโหลดขึ้น cloud เปล่าๆ
            $tmpPath = $_FILES['chat_image']['tmp_name'];

            $moderation = new ChatModerationService($conn);
            $modResult = $moderation->scanImage($tmpPath);
            if (
                $modResult['verdict'] === ChatModerationService::VERDICT_REJECT
                && defined('CHAT_MODERATION_BLOCK')
                && CHAT_MODERATION_BLOCK
            ) {
                $moderation->logScan(null, $me, $partner, 'image', '[รูปภาพ]', $modResult);
                http_response_code(400);
                echo $moderation->formatUserMessage($modResult);
                exit();
            }

            // ผ่าน moderation → อัปขึ้น Cloud Object Storage (R2/S3) หรือ fallback local
            // คืนค่าเป็น URL เต็ม (cloud) หรือชื่อไฟล์ (local) เก็บลง image_path ได้ทั้งคู่
            $image_name = secureUploadCloud($_FILES['chat_image'], 'chat', __DIR__ . '/../uploads');

            $stmt = $conn->prepare("INSERT INTO messages (sender_name, receiver_name, message, image_path) VALUES (?, ?, '', ?)");
            $stmt->bind_param("sss", $me, $partner, $image_name);
            $stmt->execute();

            // Real-time push (no-op if Redis unavailable → SSE DB-poll fallback)
            chatRealtimePublish($me, $partner);

            if ($modResult['verdict'] !== ChatModerationService::VERDICT_PASS) {
                $messageId = (int)$conn->insert_id;
                $moderation->updateMessageModeration($messageId, $modResult);
                $moderation->logScan($messageId, $me, $partner, 'image', '[รูปภาพ]', $modResult);
            }

            try {
                flixo_notify_chat_message($conn, $me, $partner, '📷 ส่งรูปภาพให้คุณ');
            } catch (Throwable $notifyError) {
                error_log('ChatController send_image: notify failed — ' . $notifyError->getMessage());
            }

            echo "success";
        }
    } catch (Exception $e) {
         echo "error";
    }
}


if ($action == 'update_status') {
    header('Content-Type: application/json'); 
    $oid = (int)$_POST['oid'];
    $type = $_POST['type'];
    
    $orderModel = new Order($conn);
    $order = $orderModel->findById($oid);
    
    if (!$order) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบออเดอร์']);
        exit();
    }


    $currentUser = ['username' => $me, 'role' => 'user'];
    if ($me === $order['buyer_name']) $currentUser['role'] = 'buyer';

    try {
        $new_status = '';
        $reason = '';
        if ($type == 'confirm_offer') {
            $new_status = OrderStateMachine::PENDING_PAYMENT;
            $reason = 'ผู้ซื้อยืนยันรับดีล';
        } elseif ($type == 'complete_order') {
            $new_status = OrderStateMachine::COMPLETED;
            $reason = 'ผู้ซื้อยืนยันได้รับสินค้าแล้ว';
        } else {
            throw new Exception('คำสั่งไม่ถูกต้อง');
        }


        OrderStateMachine::validate($order, $new_status, $currentUser);
        

        $orderModel->changeStatus($oid, $order['status'], $new_status, $me, $reason);

        try {
            if ($type == 'confirm_offer') {
                createNotification($conn, $order['seller_name'], "🤝 ผู้ซื้อตอบรับข้อเสนอออเดอร์ #$oid แล้ว — รอการชำระเงิน", "dashboard.php?tab=orders");
            } else {
                createNotification($conn, $order['seller_name'], "🎉 ออเดอร์ #$oid สำเร็จ! ผู้ซื้อได้รับของแล้ว เงินเข้าสู่ Wallet ของคุณเรียบร้อย", "earnings.php");
            }
        } catch (Throwable $notifyError) {
            error_log('ChatController update_status: notify failed — ' . $notifyError->getMessage());
        }

        $response = ['status' => 'success'];
        if ($type == 'confirm_offer') {
            $response['command'] = 'redirect';
            $response['url'] = 'buy_product.php?order_id=' . $oid; 
        } else {
            $response['command'] = 'reload_chat';
        }
        echo json_encode($response);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit();
}


if ($action == 'save_tracking') {
    header('Content-Type: application/json; charset=utf-8');

    $oid = (int)($_POST['oid'] ?? 0);
    $track = trim(filter_var($_POST['tracking'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
    $courier = trim(filter_var($_POST['courier'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));

    if ($oid <= 0 || $track === '' || $courier === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $orderModel = new Order($conn);
    $order = $orderModel->findById($oid);

    if (!$order) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบออเดอร์'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $sessionRole = strtolower((string) ($_SESSION['role'] ?? 'user'));
    $currentUser = ['username' => $me, 'role' => 'user'];
    if ($me === $order['seller_name']) {
        $currentUser['role'] = 'seller';
    } elseif ($sessionRole === 'admin' || $sessionRole === 'owner') {
        $currentUser['role'] = 'admin';
    }

    try {
        if ($order['status'] === OrderStateMachine::SHIPPED) {
            $orderModel->updateTracking($oid, $track, $courier);
            try {
                (new CourierService($conn))->onShipmentCreated($oid, $courier, $track);
            } catch (Throwable $e) {
                error_log('save_tracking courier update: ' . $e->getMessage());
            }
            echo json_encode(['status' => 'success', 'message' => 'อัปเดตเลขพัสดุแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        OrderStateMachine::validate($order, OrderStateMachine::SHIPPED, $currentUser);

        if (!$orderModel->updateTracking($oid, $track, $courier)) {
            throw new Exception('บันทึกเลขพัสดุไม่สำเร็จ');
        }

        if (!$orderModel->changeStatus($oid, $order['status'], OrderStateMachine::SHIPPED, $me, "จัดส่งผ่าน $courier ($track)")) {
            throw new Exception('อัปเดตสถานะออเดอร์ไม่สำเร็จ');
        }

        try {
            (new CourierService($conn))->onShipmentCreated($oid, $courier, $track);
        } catch (Throwable $e) {
            error_log('save_tracking courier enrich: ' . $e->getMessage());
        }

        createNotification(
            $conn,
            $order['buyer_name'],
            "🚚 ผู้ขายจัดส่งออเดอร์ #{$oid} แล้ว\nขนส่ง: {$courier}\nเลขพัสดุ: {$track}",
            'dashboard.php?tab=orders'
        );

        echo json_encode(['status' => 'success', 'message' => 'แจ้งเลขพัสดุเรียบร้อย'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
?>
