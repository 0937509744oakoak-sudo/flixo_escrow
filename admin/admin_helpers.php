<?php
// admin/admin_helpers.php — shared stats & helpers for admin panel

require_once __DIR__ . '/../includes/OrderStateMachine.php';
require_once __DIR__ . '/../includes/support_chat.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../includes/ChatModerationService.php';

function adminCurrentPage(): string
{
    return basename($_SERVER['PHP_SELF'] ?? 'index.php');
}

function adminFetchCount(mysqli $conn, string $sql, string $types = '', ...$params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['cnt'] ?? $row['total'] ?? 0);
}

function adminGetBadgeCounts(mysqli $conn): array
{
    $waitingVerify = adminFetchCount(
        $conn,
        'SELECT COUNT(*) AS cnt FROM orders WHERE status = ?',
        's',
        OrderStateMachine::WAITING_VERIFY
    );

    $pendingWithdrawals = adminFetchCount(
        $conn,
        "SELECT COUNT(*) AS cnt FROM withdrawal_requests WHERE status = 'pending'"
    );

    $openDisputes = adminFetchCount(
        $conn,
        "SELECT COUNT(*) AS cnt FROM disputes WHERE status IN ('SELLER_RESPONSE','EVIDENCE_REVIEW','SETTLEMENT_PENDING','PENDING')"
    );

    $fraudQueue = 0;
    $colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'image_fraud_status'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $fraudQueue = adminFetchCount(
            $conn,
            "SELECT COUNT(*) AS cnt FROM products WHERE image_fraud_status IN ('flagged', 'rejected', 'pending')"
        );
    }

    $supportUnread = getSupportUnreadCount($conn, getSupportUsername());

    $chatModerationQueue = 0;
    if (class_exists('ChatModerationService')) {
        $modService = new ChatModerationService($conn);
        $chatModerationQueue = $modService->getPendingCount();
    }

    return [
        'waiting_verify' => $waitingVerify,
        'pending_withdrawals' => $pendingWithdrawals,
        'open_disputes' => $openDisputes,
        'fraud_queue' => $fraudQueue,
        'chat_moderation_queue' => $chatModerationQueue,
        'support_unread' => $supportUnread,
    ];
}

function adminGetDashboardStats(mysqli $conn): array
{
    $badges = adminGetBadgeCounts($conn);

    $escrowBalance = adminFetchCount(
        $conn,
        "SELECT COALESCE(SUM(amount), 0) AS total FROM orders WHERE status IN (?, ?)",
        'ss',
        OrderStateMachine::PAID,
        OrderStateMachine::SHIPPED
    );

    $gmv = adminFetchCount(
        $conn,
        'SELECT COALESCE(SUM(amount), 0) AS total FROM orders WHERE status = ?',
        's',
        OrderStateMachine::COMPLETED
    );

    $totalUsers = adminFetchCount(
        $conn,
        "SELECT COUNT(*) AS cnt FROM users WHERE role = 'user'"
    );

    $totalOrders = adminFetchCount($conn, 'SELECT COUNT(*) AS cnt FROM orders');

    $productModel = new Product($conn);
    $activeProducts = $productModel->countAll();

    $newUsersMonth = 0;
    $userColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    if ($userColCheck && $userColCheck->num_rows > 0) {
        $newUsersMonth = adminFetchCount(
            $conn,
            "SELECT COUNT(*) AS cnt FROM users WHERE role = 'user' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
        );
    }

    $ordersToday = adminFetchCount(
        $conn,
        'SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) = CURDATE()'
    );

    $completedToday = adminFetchCount(
        $conn,
        "SELECT COUNT(*) AS cnt FROM order_status_logs WHERE new_status = ? AND DATE(created_at) = CURDATE()",
        's',
        OrderStateMachine::COMPLETED
    );

    $disputeOrders = adminFetchCount(
        $conn,
        'SELECT COUNT(*) AS cnt FROM orders WHERE status = ?',
        's',
        OrderStateMachine::DISPUTE
    );

    return array_merge($badges, [
        'escrow_balance' => (float)$escrowBalance,
        'gmv' => (float)$gmv,
        'total_users' => $totalUsers,
        'total_orders' => $totalOrders,
        'active_products' => $activeProducts,
        'new_users_month' => $newUsersMonth,
        'orders_today' => $ordersToday,
        'completed_today' => $completedToday,
        'dispute_orders' => $disputeOrders,
    ]);
}

function adminGetRecentOrders(mysqli $conn, int $limit = 8): array
{
    $stmt = $conn->prepare(
        'SELECT id, item_name, buyer_name, seller_name, amount, status, created_at
         FROM orders ORDER BY created_at DESC LIMIT ?'
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function adminGetPendingEscrow(mysqli $conn, int $limit = 6): array
{
    $paid = OrderStateMachine::PAID;
    $shipped = OrderStateMachine::SHIPPED;
    $stmt = $conn->prepare(
        "SELECT id, item_name, buyer_name, seller_name, amount, status, created_at
         FROM orders WHERE status IN (?, ?) ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bind_param('ssi', $paid, $shipped, $limit);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function adminGetOrderStatusBreakdown(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status ORDER BY cnt DESC'
    );
    if (!$result) {
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function adminFormatMoney(float $amount): string
{
    return '฿' . number_format($amount, 2);
}

function adminFormatDate(?string $datetime): string
{
    if (!$datetime) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

function adminStatusBadge(string $status): string
{
    $label = OrderStateMachine::getLabel($status);
    $color = OrderStateMachine::getColor($status);
    return '<span class="admin-badge" style="background:' . htmlspecialchars($color) . '22;color:' . htmlspecialchars($color) . ';">'
        . htmlspecialchars($label) . '</span>';
}
