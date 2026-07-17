<?php
// controllers/OrderController.php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/order_helper.php'; 
require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../includes/OmiseService.php';
require_once __DIR__ . '/../includes/omise_helper.php';
require_once __DIR__ . '/../models/OmisePayment.php';
verifyCsrfToken($_POST['csrf_token'] ?? '');

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user'])) throw new Exception('Unauthorized', 403); 

    $currentUser = [
        'username' => $_SESSION['user'],
        'role' => $_SESSION['role'] ?? 'user'
    ];

    $action = $_POST['action'] ?? '';
    $orderModel = new Order($conn);


    if ($action == 'create_offer') {
        $buyer = trim(filter_var($_POST['partner'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
        $item = trim(filter_var($_POST['item'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
        $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
        $seller = $currentUser['username'];

        if ($price === false || $price <= 0 || empty($item) || empty($buyer)) {
            throw new Exception("กรุณากรอกข้อมูลสินค้าและราคาให้ถูกต้อง");
        }
        if ($buyer === $seller) {
            throw new Exception('ไม่สามารถสร้างออเดอร์ให้ตัวเองได้');
        }
        if (mb_strlen($item) > 200) {
            $item = mb_substr($item, 0, 200);
        }

        if (!OrderStateMachine::canUserPerformAction(OrderStateMachine::PENDING_PAYMENT, 'seller')) {
            throw new Exception("คุณไม่มีสิทธิ์สร้างออเดอร์");
        }

        $orderId = $orderModel->createOffer($buyer, $seller, $item, $price);
        if ($orderId) {
            createNotification($conn, $buyer, "🤝 $seller เสนอดีลตกลงกันให้คุณแล้ว (ออเดอร์ #$orderId)", "buy_product.php?order_id=$orderId");
            echo json_encode(['status' => 'success', 'order_id' => $orderId], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception("ไม่สามารถสร้างดีลได้");
        }
    }

    elseif ($action == 'buy_from_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $buyer = $currentUser['username'];
        $productModel = new Product($conn);
        $product = $productModel->getById($productId);

        if (!$product) {
            throw new Exception('ไม่พบสินค้านี้');
        }
        if ($product['seller_name'] === $buyer) {
            throw new Exception('ไม่สามารถซื้อสินค้าของตัวเองได้');
        }

        $orderId = $orderModel->createFromProduct(
            $buyer,
            $product['seller_name'],
            $product['product_name'],
            $product['price'],
            $productId
        );

        if ($orderId) {
            createNotification(
                $conn,
                $product['seller_name'],
                "🛒 $buyer สั่งซื้อ \"{$product['product_name']}\" แล้ว (ออเดอร์ #$orderId)",
                "dashboard.php?tab=orders"
            );
            echo json_encode([
                'status' => 'success',
                'order_id' => $orderId,
                'redirect' => 'buy_product.php?order_id=' . $orderId
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('ไม่สามารถสร้างออเดอร์ได้');
        }
    }


    elseif ($action == 'upload_slip') {
       
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $windowSeconds = 600;
        $maxAttempts = 5;
        $check = $conn->prepare("SELECT COUNT(*) AS cnt FROM slip_upload_attempts WHERE (username = ? OR ip = ?) AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $check->bind_param("ssi", $currentUser['username'], $ip, $windowSeconds);
        $check->execute();
        $cnt = $check->get_result()->fetch_assoc()['cnt'] ?? 0;
        if ($cnt >= $maxAttempts) {
            throw new Exception('คุณส่งสลิปบ่อยเกินไป กรุณารอ 10 นาที แล้วลองใหม่อีกครั้ง');
        }

        $order_id = (int)$_POST['order_id'];
        $order = $orderModel->findById($order_id); 

        if (!$order) throw new Exception('ไม่พบรายการคำสั่งซื้อนี้'); 

        if ($currentUser['username'] === $order['buyer_name']) {
            $currentUser['role'] = 'buyer';
        } elseif ($currentUser['username'] === $order['seller_name']) {
            $currentUser['role'] = 'seller';
        } else {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการกับคำสั่งซื้อนี้');
        }

        $payableGuard = guardOrderPaymentAction($order);
        if ($payableGuard !== null) {
            echo json_encode($payableGuard, JSON_UNESCAPED_UNICODE);
            exit();
        }

        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ins = $conn->prepare("INSERT INTO slip_upload_attempts (order_id, username, ip) VALUES (?, ?, ?)");
        $ins->bind_param("iss", $order_id, $currentUser['username'], $ip);
        $ins->execute();
        $ins->close();

        $target_dir = flixoUploadsSubdir('slips');
        $tmp_path = $_FILES["slip_image"]['tmp_name'] ?? '';

        // Dedup + integrity check from the TEMP file, BEFORE storing anywhere,
        // so a duplicate is rejected without uploading (cloud or disk).
        $slipHash = (is_string($tmp_path) && is_uploaded_file($tmp_path)) ? hash_file('sha256', $tmp_path) : null;
        $slipHashExists = false;
        if ($slipHash !== null) {
            $dupStmt = $conn->prepare("SELECT id, order_id FROM slip_hashes WHERE slip_hash = ? LIMIT 1");
            $dupStmt->bind_param('s', $slipHash);
            $dupStmt->execute();
            $dup = $dupStmt->get_result()->fetch_assoc();
            if ($dup && (int)$dup['order_id'] !== $order_id) {
                throw new Exception('สลิปนี้เคยถูกใช้ในระบบแล้ว ไม่สามารถใช้ซ้ำได้');
            }
            $slipHashExists = (bool) $dup;
        }

        // Store slip → Cloud Object Storage (PRIVATE: returns an object key, not a
        // public URL) or local disk fallback. SlipController streams it to
        // authorized users only.
        $new_filename = secureUploadCloud($_FILES["slip_image"], 'slips', $target_dir, 5242880, false);

        if ($slipHash !== null && !$slipHashExists) {
            $insHash = $conn->prepare("INSERT INTO slip_hashes (slip_hash, order_id, username) VALUES (?, ?, ?)");
            $insHash->bind_param('sis', $slipHash, $order_id, $currentUser['username']);
            $insHash->execute();
        }

        // Local file path to hand to the EasySlip API (it POSTs the file via
        // CURLFILE). Cloud path: the temp upload is still present (not moved).
        // Local path: the temp file was moved into uploads/slips.
        $full_file_path = (is_string($tmp_path) && is_file($tmp_path))
            ? $tmp_path
            : orderSlipPath($new_filename);

        $api_key = SLIP_API_KEY;
        $api_url = SLIP_API_URL;
        $alreadyWaiting = ($order['status'] === OrderStateMachine::WAITING_VERIFY);
        
        // No API key, or no readable local file to send → manual admin review.
        if (empty($api_key) || empty($full_file_path) || !is_file($full_file_path)) {
            $orderModel->updatePaymentSlip($order_id, $new_filename);
            if (!$alreadyWaiting) {
                OrderStateMachine::validate($order, OrderStateMachine::WAITING_VERIFY, $currentUser);
                $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::WAITING_VERIFY, $currentUser['username'], 'ข้ามการตรวจ API (ไม่มี API key) - รอแอดมินตรวจแบบ Manual');
                createNotification($conn, $order['seller_name'], "💳 ผู้ซื้อส่งสลิปชำระเงินออเดอร์ #$order_id แล้ว — รอตรวจสอบยอดเงิน", "dashboard.php?tab=orders");
            }
            echo json_encode(['status' => 'success', 'message' => 'ส่งสลิปเรียบร้อย (รอแอดมินตรวจสอบเนื่องจาก API ยังไม่ได้ตั้งค่า)']);
            exit();
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array('file' => new CURLFILE($full_file_path)),
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $api_key
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        $orderModel->updatePaymentSlip($order_id, $new_filename);

        if ($err) {
            if (!$alreadyWaiting) {
                OrderStateMachine::validate($order, OrderStateMachine::WAITING_VERIFY, $currentUser);
                $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::WAITING_VERIFY, $currentUser['username'], 'API ไม่ตอบสนอง: เปลี่ยนเป็นรอแอดมินตรวจ (Manual)');
                createNotification($conn, $order['seller_name'], "💳 ผู้ซื้อส่งสลิปชำระเงินออเดอร์ #$order_id แล้ว — รอตรวจสอบยอดเงิน", "dashboard.php?tab=orders");
            }
            echo json_encode(['status' => 'success', 'message' => 'ส่งสลิปแล้ว (รอแอดมินตรวจสอบเนื่องจากเชื่อมต่อ AI ไม่ได้)']);
            exit();
        }

        $result = json_decode($response, true);

        // 🔥 ถ้า API ไม่สามารถอ่าน QR code ให้ fallback เป็น manual verification
        $qr_errors = ['qrcode_not_found', 'invalid_qr', 'qr_not_recognized'];
        $api_error = strtolower(isset($result['message']) ? $result['message'] : '');
        
        if (in_array($api_error, $qr_errors) || (isset($result['status']) && $result['status'] != 200 && strpos($api_error, 'qr') !== false)) {
            if (!$alreadyWaiting) {
                OrderStateMachine::validate($order, OrderStateMachine::WAITING_VERIFY, $currentUser);
                $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::WAITING_VERIFY, $currentUser['username'], 'API ไม่สามารถอ่าน QR code ได้ - เปลี่ยนเป็นรอแอดมินตรวจแบบ Manual');
                createNotification($conn, $order['seller_name'], "💳 ผู้ซื้อส่งสลิปชำระเงินออเดอร์ #$order_id แล้ว — รอตรวจสอบยอดเงิน", "dashboard.php?tab=orders");
            }
            echo json_encode(['status' => 'success', 'message' => 'ส่งสลิปแล้ว (ระบบไม่สามารถอ่าน QR code ได้ รอแอดมินตรวจสอบแบบ Manual)']);
            exit();
        }

        if (isset($result['status']) && $result['status'] == 200) {
            
            $slip_amount = extractSlipAmount($result['data'] ?? []);
            $order_amount = round((float) $order['amount'], 2);

            if ($slip_amount <= 0) {
                throw new Exception('ไม่สามารถอ่านยอดเงินจากสลิปได้ กรุณาอัปโหลดสลิปที่ชัดเจนหรือรอแอดมินตรวจสอบ');
            }

            if ($slip_amount + 0.009 >= $order_amount) {
                if (!$alreadyWaiting) {
                    $step1_status = OrderStateMachine::WAITING_VERIFY;
                    OrderStateMachine::validate($order, $step1_status, $currentUser);
                    $orderModel->changeStatus($order_id, $order['status'], $step1_status, $currentUser['username'], 'อัปโหลดสลิปสำเร็จผ่าน API');
                    $order['status'] = $step1_status;
                }

                $systemAdmin = ['username' => 'System_API', 'role' => 'admin'];
                
                $step2_status = OrderStateMachine::PAID; 
                OrderStateMachine::validate($order, $step2_status, $systemAdmin);
                $orderModel->changeStatus($order_id, $order['status'], $step2_status, 'System_API', 'ตรวจสลิปอัตโนมัติสำเร็จผ่าน API');

             
                $meta = json_encode([
                    'via' => 'slip_api',
                    'verified_by' => 'System_API',
                    'slip_amount' => $slip_amount,
                    'order_amount' => $order_amount,
                    'raw_amount' => $result['data']['amount'] ?? null,
                ], JSON_UNESCAPED_UNICODE);
                $tx = $conn->prepare("INSERT INTO transaction_logs (order_id, type, amount, currency, meta) VALUES (?, ?, ?, ?, ?)");
                $type = 'slip_auto_verified';
                $currency = 'THB';
                $tx->bind_param("isdss", $order_id, $type, $slip_amount, $currency, $meta);
                $tx->execute();
                $tx->close();

                createNotification($conn, $order['seller_name'], "💰 ยอดเงินออเดอร์ #$order_id ได้รับการยืนยันอัตโนมัติแล้ว กรุณาจัดส่งสินค้า", "dashboard.php?tab=orders");
                createNotification($conn, $order['buyer_name'], "✅ ยืนยันการชำระเงินออเดอร์ #$order_id แล้ว — เงินพักใน Escrow อย่างปลอดภัย รอผู้ขายจัดส่ง", "buy_product.php?order_id=$order_id");

                echo json_encode(['status' => 'success', 'message' => 'ตรวจสอบสลิปสำเร็จ! ยอดเงินถูกต้อง']);
            } else {
                throw new Exception(
                    'ยอดเงินในสลิป (' . number_format($slip_amount, 2) . ' บาท) ไม่ตรงกับราคาสินค้า (' . number_format($order_amount, 2) . ' บาท)'
                );
            }

        } else {
            $error_msg = isset($result['message']) ? $result['message'] : 'ไม่สามารถอ่านข้อมูลสลิปได้';
            throw new Exception("ตรวจไม่ผ่าน: " . $error_msg);
        }
    }

    
    elseif ($action == 'open_dispute') {
        $order_id = (int)$_POST['order_id'];
        $reason = trim($_POST['reason'] ?? 'สินค้ามีปัญหา/ไม่ได้รับสินค้า');
        $description = trim($_POST['description'] ?? '');
        $order = $orderModel->findById($order_id);

        if (!$order) throw new Exception('ไม่พบรายการคำสั่งซื้อนี้');

        if ($currentUser['username'] === $order['buyer_name']) {
            $currentUser['role'] = 'buyer';
        } elseif ($currentUser['username'] === $order['seller_name']) {
            $currentUser['role'] = 'seller';
        } else {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการกับคำสั่งซื้อนี้');
        }

        // Create dispute record
        require_once __DIR__ . '/../includes/DisputeFlowService.php';
        $disputeModel = new Dispute($conn);
        $existing = $disputeModel->findByOrderId($order_id);
        if ($existing && in_array($existing['status'], DisputeFlowService::openStatuses(), true)) {
            throw new Exception('มีข้อพิพาทเปิดอยู่แล้วสำหรับออเดอร์นี้');
        }

        $dispute_id = $disputeModel->create($order_id, $currentUser['username'], $reason, $description);
        
        if (!$dispute_id) {
            throw new Exception('ไม่สามารถสร้างข้อพิพาทได้');
        }
        $disputeModel->setSlaDue((int)$dispute_id, 7);
        (new DisputeFlowService($conn))->initializeNewDispute((int)$dispute_id);

        // Change order status to DISPUTE
        OrderStateMachine::validate($order, OrderStateMachine::DISPUTE, $currentUser);
        $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::DISPUTE, $currentUser['username'], "เปิดข้อพิพาท: " . $reason);
        
        // Notify both parties
        $buyer_name = $order['buyer_name'];
        $seller_name = $order['seller_name'];
        createNotification($conn, $seller_name, "⚠️ ผู้ซื้อเปิดข้อพิพาทออเดอร์ #$order_id — กรุณาชี้แจงภายใน " . DisputeFlowService::sellerResponseHours() . " ชม.", "dispute_detail.php?dispute_id=$dispute_id");
        createNotification($conn, $buyer_name, "⚠️ คุณเปิดข้อพิพาทออเดอร์ #$order_id แล้ว", "dispute_detail.php?dispute_id=$dispute_id");

        echo json_encode(['status' => 'success', 'message' => 'เปิดข้อพิพาทสำเร็จ ระบบระงับการโอนเงินไว้เพื่อรอแอดมินตรวจสอบ', 'dispute_id' => $dispute_id], JSON_UNESCAPED_UNICODE);
        exit;
    }

   
    elseif ($action == 'cancel_order') {
        $order_id = (int)$_POST['order_id'];
        $order = $orderModel->findById($order_id);

        if (!$order) throw new Exception('ไม่พบรายการคำสั่งซื้อนี้');

        if ($currentUser['username'] === $order['buyer_name']) {
            $currentUser['role'] = 'buyer';
        } elseif ($currentUser['username'] === $order['seller_name']) {
            $currentUser['role'] = 'seller';
        } else {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการกับคำสั่งซื้อนี้');
        }

        OrderStateMachine::validate($order, OrderStateMachine::CANCELLED, $currentUser);
        $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::CANCELLED, $currentUser['username'], 'ผู้ใช้ยกเลิกรายการ');
        createNotification($conn, $order['seller_name'], "🚫 ออเดอร์ #$order_id ถูกยกเลิกแล้ว", "dashboard.php?tab=orders");

        echo json_encode(['status' => 'success', 'message' => 'ยกเลิกออเดอร์สำเร็จ']);
    }
    
    
    elseif ($action == 'confirm_receive') { 
        $order_id = (int)$_POST['order_id'];
        $order = $orderModel->findById($order_id);
        
        if (!$order) throw new Exception('ไม่พบรายการคำสั่งซื้อนี้');
        
        if ($currentUser['username'] === $order['buyer_name']) {
            $currentUser['role'] = 'buyer';
        } elseif ($currentUser['username'] === $order['seller_name']) {
            $currentUser['role'] = 'seller';
        } else {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการกับคำสั่งซื้อนี้');
        }
        
        OrderStateMachine::validate($order, OrderStateMachine::COMPLETED, $currentUser);
        $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::COMPLETED, $currentUser['username'], 'ผู้ซื้อยืนยันได้รับสินค้าแล้ว');
        createNotification($conn, $order['seller_name'], "🎉 ออเดอร์ #$order_id สำเร็จ! ผู้ซื้อได้รับของแล้ว เงินเข้าสู่ Wallet ของคุณเรียบร้อย", "earnings.php");
        
        echo json_encode(['status' => 'success']);
    }

    elseif ($action === 'pay_omise_card') {
        if (!OmiseService::isConfigured()) {
            throw new Exception('ระบบ Omise ยังไม่ได้ตั้งค่า');
        }
        $order_id = (int)($_POST['order_id'] ?? 0);
        $token = trim($_POST['omise_token'] ?? '');
        if ($token === '') {
            throw new Exception('ไม่พบข้อมูลบัตร (token)');
        }
        $order = $orderModel->findById($order_id);
        if (!$order || $order['buyer_name'] !== $currentUser['username']) {
            throw new Exception('ไม่มีสิทธิ์ชำระออเดอร์นี้');
        }
        $payableGuard = guardOrderPaymentAction($order);
        if ($payableGuard !== null) {
            echo json_encode($payableGuard, JSON_UNESCAPED_UNICODE);
            exit();
        }

        $omise = new OmiseService();
        $satang = OmiseService::toSatang((float)$order['amount']);
        $charge = $omise->createCardCharge($token, $satang, 'FLIXO Order #' . $order_id, ['order_id' => (string)$order_id]);

        if ($omise->isPaid($charge)) {
            markOrderPaidFromOmise($conn, $order_id, $charge, 'card');
            echo json_encode(['status' => 'success', 'message' => 'ชำระเงินด้วยบัตรสำเร็จ!'], JSON_UNESCAPED_UNICODE);
        } else {
            $fail = $charge['failure_message'] ?? 'การชำระเงินไม่สำเร็จ';
            $payModel = new OmisePayment($conn);
            $payModel->record($order_id, $charge['id'] ?? 'unknown', 'card', (float)$order['amount'], $satang, $charge['status'] ?? 'failed', $charge, $fail);
            throw new Exception($fail);
        }
    }

    elseif ($action === 'create_omise_promptpay') {
        if (!OmiseService::isConfigured()) {
            throw new Exception('ระบบ Omise ยังไม่ได้ตั้งค่า');
        }
        $order_id = (int)($_POST['order_id'] ?? 0);
        $order = $orderModel->findById($order_id);
        if (!$order || $order['buyer_name'] !== $currentUser['username']) {
            throw new Exception('ไม่มีสิทธิ์ชำระออเดอร์นี้');
        }
        $payableGuard = guardOrderPaymentAction($order);
        if ($payableGuard !== null) {
            echo json_encode($payableGuard, JSON_UNESCAPED_UNICODE);
            exit();
        }

        $omise = new OmiseService();
        $satang = OmiseService::toSatang((float)$order['amount']);
        $source = $omise->createPromptPaySource($satang, 'FLIXO Order #' . $order_id, ['order_id' => (string)$order_id]);
        $sourceId = $source['id'] ?? '';
        if ($sourceId === '') {
            throw new Exception('ไม่สามารถสร้าง QR PromptPay ได้');
        }

        $payModel = new OmisePayment($conn);
        $payModel->record($order_id, $sourceId, 'promptpay', (float)$order['amount'], $satang, $source['status'] ?? 'pending', $source);

        $qrUrl = $source['scannable_code']['image']['download_uri'] ?? null;
        echo json_encode([
            'status' => 'success',
            'source_id' => $sourceId,
            'qr_url' => $qrUrl,
            'expires_at' => $source['scannable_code']['expires_at'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    elseif ($action === 'check_omise_promptpay') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $source_id = trim($_POST['source_id'] ?? '');
        $order = $orderModel->findById($order_id);
        if (!$order || $order['buyer_name'] !== $currentUser['username']) {
            throw new Exception('ไม่มีสิทธิ์');
        }
        $result = processOmisePromptPaySource($conn, $order_id, $source_id);
        if (!empty($result['paid'])) {
            echo json_encode(['status' => 'success', 'paid' => true, 'message' => 'ชำระเงิน PromptPay สำเร็จ!'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['status' => 'pending', 'paid' => false, 'omise_status' => $result['status'] ?? 'pending'], JSON_UNESCAPED_UNICODE);
        }
    }

    else {
        throw new Exception('Invalid Action');
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>