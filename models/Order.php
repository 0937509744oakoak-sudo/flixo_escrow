<?php
// models/Order.php

class Order {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    public function findById($id) {
        $sql = "SELECT * FROM orders WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

  
    public function createOffer($buyer, $seller, $item, $price, $productId = 0, $itemImage = '') {
    
        $status = OrderStateMachine::CREATED;
        $itemImage = trim((string) $itemImage);
        $sql = "INSERT INTO orders (buyer_name, seller_name, item_name, amount, status, product_id, item_image) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssisis", $buyer, $seller, $item, $price, $status, $productId, $itemImage);
        
        if ($stmt->execute()) {
            $orderId = $this->conn->insert_id;
          
            $this->logStatusChange($orderId, 'CREATED', $status, $seller, 'ตกลงดีลในห้องแชท');
            return $orderId;
        }
        return false;
    }

    public function createFromProduct($buyer, $seller, $item, $price, $productId, $itemImage = '') {
        $status = OrderStateMachine::PENDING_PAYMENT;
        $itemImage = trim((string) $itemImage);
        $sql = "INSERT INTO orders (buyer_name, seller_name, item_name, amount, status, product_id, item_image) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssisis", $buyer, $seller, $item, $price, $status, $productId, $itemImage);

        if ($stmt->execute()) {
            $orderId = $this->conn->insert_id;
            $this->logStatusChange($orderId, '', $status, $buyer, 'สั่งซื้อจากประกาศขาย');
            return $orderId;
        }
        return false;
    }


    public function cancelExpiredPendingOrders($hours = 24) {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $pendingStatus = OrderStateMachine::PENDING_PAYMENT;
        $cancelledStatus = OrderStateMachine::CANCELLED;

        $sql = "SELECT id, status FROM orders WHERE status = ? AND created_at < ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("ss", $pendingStatus, $threshold);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            return;
        }

        while ($row = $result->fetch_assoc()) {
            try {
                $this->changeStatus($row['id'], $row['status'], $cancelledStatus, 'system', 'ยกเลิกออเดอร์อัตโนมัติเมื่อไม่ชำระเงินภายใน 24 ชั่วโมง');
            } catch (Throwable $e) {
                error_log('cancelExpiredPendingOrders #' . $row['id'] . ': ' . $e->getMessage());
            }
        }
    }

    public function getOrderStatusLogs($orderId) {
        $sql = "SELECT * FROM order_status_logs WHERE order_id = ? ORDER BY id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        return $stmt->get_result();
    }

  
    public function changeStatus($orderId, $oldStatus, $newStatus, $actionBy, $reason = '', $skipWalletSync = false) {
      
        $sql_update = "UPDATE orders SET status = ? WHERE id = ?";
        $stmt_update = $this->conn->prepare($sql_update);
        $stmt_update->bind_param("si", $newStatus, $orderId);
        
        if ($stmt_update->execute()) {
          
            $this->logStatusChange($orderId, $oldStatus, $newStatus, $actionBy, $reason);

            if (!$skipWalletSync) {
                require_once __DIR__ . '/../includes/WalletService.php';
                $walletService = new WalletService($this->conn);
                $walletService->syncOrderStatus((int)$orderId, $oldStatus, $newStatus, $actionBy);
            }
            
            // Send email notification for status changes
            if (defined('MAIL_ENABLED') && MAIL_ENABLED) {
                // Include functions if not already loaded
                if (!function_exists('notifyOrderStatusChange')) {
                    require_once __DIR__ . '/../includes/functions.php';
                }
                // Send notification asynchronously
                notifyOrderStatusChange($this->conn, $orderId, $newStatus, $oldStatus);
            }
            
            return true;
        }
        return false;
    }


    public function updatePaymentSlip($orderId, $slipImage) {
        $sql = "UPDATE orders SET slip_image = ?, paid_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("si", $slipImage, $orderId);
        return $stmt->execute();
    }


    private function logStatusChange($orderId, $oldStatus, $newStatus, $actionBy, $reason) {
        $sql_log = "INSERT INTO order_status_logs (order_id, old_status, new_status, changed_by, reason) VALUES (?, ?, ?, ?, ?)";
        $stmt_log = $this->conn->prepare($sql_log);
        $stmt_log->bind_param("issss", $orderId, $oldStatus, $newStatus, $actionBy, $reason);
        $stmt_log->execute();
    }

    public function updatePayment($orderId, $slipImage) {
        $status = OrderStateMachine::WAITING_VERIFY;
        $sql = "UPDATE orders SET slip_image = ?, status = ?, paid_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $slipImage, $status, $orderId);
        return $stmt->execute();
    }

    public function updateTracking($orderId, $tracking, $courier) {
        $sql = "UPDATE orders SET tracking_number = ?, courier = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $tracking, $courier, $orderId);
        return $stmt->execute();
    }

    // 🔥 [ใหม่] ดึงสถิติหน้า Dashboard
    public function getDashboardStats($username) {
        $paid = OrderStateMachine::PAID;
        $shipped = OrderStateMachine::SHIPPED;
        
        $sql_todo = "SELECT COUNT(*) as count FROM orders WHERE
            (seller_name=? AND status=?) OR
            (buyer_name=? AND status=?) OR
            (buyer_name=? AND status=?)";
        $pending = OrderStateMachine::PENDING_PAYMENT;
        $stmt_todo = $this->conn->prepare($sql_todo);
        $todo_count = 0;
        if ($stmt_todo) {
            $stmt_todo->bind_param("ssssss", $username, $paid, $username, $shipped, $username, $pending);
            $stmt_todo->execute();
            $todo_count = $stmt_todo->get_result()->fetch_assoc()['count'] ?? 0;
        }

        $sql_money = "SELECT SUM(amount) as total FROM orders WHERE seller_name=? AND (status=? OR status=?)";
        $stmt_money = $this->conn->prepare($sql_money);
        $money_pending = 0;
        if ($stmt_money) {
            $stmt_money->bind_param("sss", $username, $paid, $shipped);
            $stmt_money->execute();
            $money_pending = $stmt_money->get_result()->fetch_assoc()['total'] ?? 0;
        }

        try {
            require_once __DIR__ . '/../includes/WalletService.php';
            (new WalletService($this->conn))->reconcileUserWallet($username);
            require_once __DIR__ . '/../models/Wallet.php';
            $walletModel = new Wallet($this->conn);
            $wallet = $walletModel->getWalletByUsername($username);
            if ($wallet) {
                $money_pending = (float)$wallet['escrow_balance'];
            }
        } catch (Throwable $e) {
            error_log('getDashboardStats wallet: ' . $e->getMessage());
            if (class_exists('ErrorLogger')) {
                ErrorLogger::write('error', 'getDashboardStats wallet: ' . $e->getMessage());
            }
        }

        return ['todo_count' => $todo_count, 'money_pending' => $money_pending];
    }

   
    public function refundToBuyer($orderId) {
        $order = $this->findById($orderId);
        if (!$order) {
            return false;
        }
        return $this->changeStatus($orderId, $order['status'], OrderStateMachine::CANCELLED, 'admin', 'คืนเงินให้ผู้ซื้อ');
    }

  
    public function forceComplete($orderId) {
        $order = $this->findById($orderId);
        if (!$order) {
            return false;
        }
        return $this->changeStatus($orderId, $order['status'], OrderStateMachine::COMPLETED, 'admin', 'แอดมินบังคับจบออเดอร์');
    }

  
    public function getEarningsStats($seller_name) {
        try {
            require_once __DIR__ . '/../includes/WalletService.php';
            (new WalletService($this->conn))->reconcileUserWallet($seller_name);

            require_once __DIR__ . '/../models/Wallet.php';
            $walletModel = new Wallet($this->conn);
            $wallet = $walletModel->getWalletByUsername($seller_name);
            if ($wallet) {
                return [
                    'total' => (float)$wallet['available_balance'],
                    'pending' => (float)$wallet['escrow_balance'],
                    'total_earned' => (float)$wallet['total_earned'],
                    'total_withdrawn' => (float)$wallet['total_withdrawn'],
                ];
            }
        } catch (Throwable $e) {
            error_log('getEarningsStats wallet: ' . $e->getMessage());
            if (class_exists('ErrorLogger')) {
                ErrorLogger::write('error', 'getEarningsStats wallet: ' . $e->getMessage());
            }
        }
        return ['total' => 0, 'pending' => 0, 'total_earned' => 0, 'total_withdrawn' => 0];
    }

   
    public function getCompletedHistory($seller_name) {
        $completed = OrderStateMachine::COMPLETED;
        $sql = "SELECT o.*, COALESCE(p.product_name, o.item_name) AS product_name,
                       COALESCE(NULLIF(o.item_image, ''), p.image_path) AS image_path
                FROM orders o
                LEFT JOIN products p ON o.product_id = p.id AND o.product_id > 0
                WHERE o.seller_name = ? AND o.status = ? ORDER BY o.id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ss", $seller_name, $completed);
        $stmt->execute();
        return $stmt->get_result();
    }

  
    public function getOrdersByRole($username, $role = 'buyer') {
        $column = ($role === 'seller') ? 'o.seller_name' : 'o.buyer_name';
        $sql = "SELECT o.*, COALESCE(p.product_name, o.item_name) AS product_name,
                       COALESCE(NULLIF(o.item_image, ''), p.image_path) AS image_path
                FROM orders o
                LEFT JOIN products p ON o.product_id = p.id AND o.product_id > 0
                WHERE $column = ? ORDER BY o.id DESC";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $username);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function getRecentOrders($username, $limit = 5) {
        $sql = "SELECT o.*, COALESCE(p.product_name, o.item_name) AS product_name,
                       COALESCE(NULLIF(o.item_image, ''), p.image_path) AS image_path
                FROM orders o
                LEFT JOIN products p ON o.product_id = p.id AND o.product_id > 0
                WHERE o.buyer_name = ? OR o.seller_name = ?
                ORDER BY o.id DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("ssi", $username, $username, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
}
?>