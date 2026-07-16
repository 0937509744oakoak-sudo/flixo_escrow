<?php
// includes/WalletService.php — ledger operations for escrow wallet

require_once __DIR__ . '/OrderStateMachine.php';
require_once __DIR__ . '/order_helper.php';
require_once __DIR__ . '/../models/Wallet.php';

class WalletService {
    private mysqli $conn;
    private Wallet $wallet;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->wallet = new Wallet($conn);
    }

    public function syncOrderStatus(int $orderId, string $oldStatus, string $newStatus, string $actionBy): void {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return;
        }
        $amount = (float)$order['amount'];
        $seller = $order['seller_name'];
        $buyer = $order['buyer_name'];

        if ($newStatus === OrderStateMachine::PAID && $oldStatus !== OrderStateMachine::PAID) {
            $this->creditEscrow($seller, $orderId, $amount, $actionBy);
        }

        if ($newStatus === OrderStateMachine::COMPLETED && $oldStatus !== OrderStateMachine::COMPLETED) {
            $this->releaseToSeller($seller, $orderId, $amount, $actionBy);
        }

        $refundFrom = [OrderStateMachine::PAID, OrderStateMachine::SHIPPED, OrderStateMachine::DISPUTE];
        if ($newStatus === OrderStateMachine::CANCELLED && in_array($oldStatus, $refundFrom, true)) {
            $this->refundBuyer($buyer, $seller, $orderId, $amount, $actionBy);
        }
    }

    public function creditEscrow(string $sellerUsername, int $orderId, float $amount, string $by): bool {
        $key = "escrow_in:order:{$orderId}";
        if ($this->wallet->hasTransaction($key)) {
            return true;
        }
        return $this->applyLedger($sellerUsername, $orderId, $amount, 'escrow', 'credit', 'escrow_in', "รับเงินเข้า Escrow ออเดอร์ #{$orderId}", $by, $key);
    }

    public function releaseToSeller(string $sellerUsername, int $orderId, float $amount, string $by): bool {
        $key = "escrow_release:order:{$orderId}";
        if ($this->wallet->hasTransaction($key)) {
            return true;
        }
        $userId = $this->wallet->getUserIdByUsername($sellerUsername);
        if (!$userId) {
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $wallet = $this->wallet->getOrCreateWallet($userId);
            $escrow = (float)$wallet['escrow_balance'];
            $releaseAmount = min($amount, $escrow);
            if ($releaseAmount <= 0) {
                $this->conn->rollback();
                return false;
            }
            $this->debitEscrow($sellerUsername, $orderId, $releaseAmount, $by, "escrow_release_debit:order:{$orderId}");
            $this->creditAvailable($sellerUsername, $orderId, $releaseAmount, $by, $key, 'escrow_release', "ปล่อยเงินจาก Escrow ออเดอร์ #{$orderId}");
            $upd = $this->conn->prepare("UPDATE wallets SET total_earned = total_earned + ? WHERE user_id = ?");
            $upd->bind_param('di', $releaseAmount, $userId);
            $upd->execute();
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('WalletService releaseToSeller: ' . $e->getMessage());
            return false;
        }
    }

    public function refundBuyer(string $buyerUsername, string $sellerUsername, int $orderId, float $amount, string $by): bool {
        $key = "refund:order:{$orderId}";
        if ($this->wallet->hasTransaction($key)) {
            return true;
        }
        $this->conn->begin_transaction();
        try {
            $sellerId = $this->wallet->getUserIdByUsername($sellerUsername);
            if ($sellerId) {
                $wallet = $this->wallet->getOrCreateWallet($sellerId);
                $escrow = (float)$wallet['escrow_balance'];
                $deduct = min($amount, $escrow);
                if ($deduct > 0) {
                    $this->debitEscrow($sellerUsername, $orderId, $deduct, $by, "refund_escrow_debit:order:{$orderId}");
                }
            }
            $this->creditAvailable($buyerUsername, $orderId, $amount, $by, $key, 'refund_credit', "คืนเงินออเดอร์ #{$orderId}");
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('WalletService refundBuyer: ' . $e->getMessage());
            return false;
        }
    }

    public function requestWithdrawal(string $username, float $amount, int $bankAccountId): array {
        $userId = $this->wallet->getUserIdByUsername($username);
        if (!$userId) {
            return ['ok' => false, 'error' => 'ไม่พบผู้ใช้'];
        }
        if ($amount < 100) {
            return ['ok' => false, 'error' => 'ถอนขั้นต่ำ ฿100'];
        }
        $wallet = $this->wallet->getOrCreateWallet($userId);
        if ($amount > (float)$wallet['available_balance']) {
            return ['ok' => false, 'error' => 'ยอดเงินไม่เพียงพอ'];
        }
        $stmt = $this->conn->prepare("SELECT id FROM user_bank_accounts WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ii', $bankAccountId, $userId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return ['ok' => false, 'error' => 'ไม่พบบัญชีธนาคาร'];
        }

        $this->conn->begin_transaction();
        try {
            $key = 'withdraw_hold:' . uniqid('', true);
            if (!$this->debitAvailable($username, null, $amount, $username, $key, 'withdrawal_hold', 'ระงับเงินรอถอน')) {
                throw new RuntimeException('ไม่สามารถหักยอดได้');
            }
            $ins = $this->conn->prepare(
                "INSERT INTO withdrawal_requests (user_id, wallet_id, bank_account_id, amount) VALUES (?, ?, ?, ?)"
            );
            $wid = (int)$wallet['id'];
            $ins->bind_param('iiid', $userId, $wid, $bankAccountId, $amount);
            $ins->execute();
            $requestId = (int)$this->conn->insert_id;
            $this->conn->commit();
            return ['ok' => true, 'request_id' => $requestId];
        } catch (Throwable $e) {
            $this->conn->rollback();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function completeWithdrawal(int $requestId, string $adminUser, ?string $payoutSlip = null, string $adminNote = ''): bool {
        $this->wallet->ensurePayoutSlipColumn();
        $stmt = $this->conn->prepare("SELECT * FROM withdrawal_requests WHERE id = ? AND status = 'pending' LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        if (!$req) {
            return false;
        }
        $userId = (int)$req['user_id'];
        $amount = (float)$req['amount'];
        $stmtU = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmtU->bind_param('i', $userId);
        $stmtU->execute();
        $username = $stmtU->get_result()->fetch_assoc()['username'] ?? '';

        $this->conn->begin_transaction();
        try {
            $slip = $payoutSlip !== null && $payoutSlip !== '' ? $payoutSlip : null;
            $note = trim($adminNote);
            $upd = $this->conn->prepare(
                "UPDATE withdrawal_requests
                 SET status = 'completed', processed_by = ?, processed_at = NOW(),
                     payout_slip = COALESCE(?, payout_slip),
                     admin_note = IF(? = '', admin_note, ?)
                 WHERE id = ?"
            );
            $upd->bind_param('ssssi', $adminUser, $slip, $note, $note, $requestId);
            $upd->execute();

            $key = "withdraw_complete:req:{$requestId}";
            $this->recordTransaction($userId, null, $amount, 'available', 'debit', 'withdrawal_complete', 0, "ถอนเงินสำเร็จ #{$requestId}", $adminUser, $key);

            $wUpd = $this->conn->prepare("UPDATE wallets SET total_withdrawn = total_withdrawn + ? WHERE user_id = ?");
            $wUpd->bind_param('di', $amount, $userId);
            $wUpd->execute();

            $meta = json_encode(['withdrawal_id' => $requestId, 'username' => $username, 'payout_slip' => $slip]);
            $tx = $this->conn->prepare("INSERT INTO transaction_logs (order_id, type, amount, currency, meta) VALUES (NULL, 'withdrawal_payout', ?, 'THB', ?)");
            $tx->bind_param('ds', $amount, $meta);
            $tx->execute();

            $this->conn->commit();

            if ($username !== '' && function_exists('createNotification')) {
                $msg = '✅ ถอนเงิน ฿' . number_format($amount, 2) . ' สำเร็จแล้ว';
                if ($slip) {
                    $msg .= ' — ดูสลิปการโอนได้ที่กระเป๋าเงิน';
                }
                createNotification($this->conn, $username, $msg, 'earnings.php');
            }
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('completeWithdrawal: ' . $e->getMessage());
            return false;
        }
    }

    public function rejectWithdrawal(int $requestId, string $adminUser, string $note = ''): bool {
        $stmt = $this->conn->prepare("SELECT w.*, u.username FROM withdrawal_requests w JOIN users u ON u.id = w.user_id WHERE w.id = ? AND w.status = 'pending' LIMIT 1");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        if (!$req) {
            return false;
        }
        $this->conn->begin_transaction();
        try {
            $upd = $this->conn->prepare(
                "UPDATE withdrawal_requests SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?"
            );
            $upd->bind_param('ssi', $note, $adminUser, $requestId);
            $upd->execute();

            $key = "withdraw_reject:req:{$requestId}";
            $this->creditAvailable($req['username'], null, (float)$req['amount'], $adminUser, $key, 'withdrawal_reject', 'คืนเงินจากคำขอถอนที่ถูกปฏิเสธ');

            $this->conn->commit();

            if (function_exists('createNotification')) {
                $reason = trim($note) !== '' ? trim($note) : 'ปฏิเสธโดยแอดมิน';
                createNotification(
                    $this->conn,
                    $req['username'],
                    '❌ คำขอถอน ฿' . number_format((float)$req['amount'], 2) . ' ถูกปฏิเสธ — คืนเงินเข้ากระเป๋าแล้ว (' . $reason . ')',
                    'earnings.php'
                );
            }
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            return false;
        }
    }

    public function splitDisputeResolution(string $buyerUsername, string $sellerUsername, int $orderId, float $buyerAmount, float $sellerAmount, string $by): bool {
        $keyBuyer = "split_refund:order:{$orderId}";
        $keySeller = "split_release:order:{$orderId}";
        if ($this->wallet->hasTransaction($keyBuyer) || $this->wallet->hasTransaction($keySeller)) {
            return true;
        }
        if ($buyerAmount < 0 || $sellerAmount < 0 || ($buyerAmount + $sellerAmount) <= 0) {
            return false;
        }

        $this->conn->begin_transaction();
        try {
            $sellerId = $this->wallet->getUserIdByUsername($sellerUsername);
            if ($sellerId) {
                $wallet = $this->wallet->getOrCreateWallet($sellerId);
                $escrow = (float) $wallet['escrow_balance'];
                $total = min($buyerAmount + $sellerAmount, $escrow);
                if ($total <= 0) {
                    throw new RuntimeException('ไม่มียอดใน Escrow');
                }
                $ratio = $total / ($buyerAmount + $sellerAmount);
                $buyerAmount = round($buyerAmount * $ratio, 2);
                $sellerAmount = round($sellerAmount * $ratio, 2);
                $this->debitEscrow($sellerUsername, $orderId, $total, $by, "split_escrow_debit:order:{$orderId}");
            }
            if ($buyerAmount > 0) {
                $this->creditAvailable($buyerUsername, $orderId, $buyerAmount, $by, $keyBuyer, 'split_refund', "คืนเงินบางส่วนออเดอร์ #{$orderId}");
            }
            if ($sellerAmount > 0) {
                $this->creditAvailable($sellerUsername, $orderId, $sellerAmount, $by, $keySeller, 'split_release', "ปล่อยเงินบางส่วนออเดอร์ #{$orderId}");
                $sellerId = $this->wallet->getUserIdByUsername($sellerUsername);
                if ($sellerId) {
                    $upd = $this->conn->prepare("UPDATE wallets SET total_earned = total_earned + ? WHERE user_id = ?");
                    $upd->bind_param('di', $sellerAmount, $sellerId);
                    $upd->execute();
                }
            }
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('WalletService splitDisputeResolution: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Backfill missing ledger entries for historical orders (idempotent).
     */
    public function reconcileUserWallet(string $username): int {
        $fixed = 0;
        $fixed += $this->cleanupInvalidRefunds($username);
        $fixed += $this->reconcileSellerOrders($username);
        $fixed += $this->reconcileBuyerOrders($username);
        return $fixed;
    }

    private function reconcileSellerOrders(string $sellerUsername): int {
        $fixed = 0;
        $stmt = $this->conn->prepare(
            "SELECT id, status, amount, buyer_name FROM orders WHERE seller_name = ? ORDER BY id ASC"
        );
        $stmt->bind_param('s', $sellerUsername);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        require_once __DIR__ . '/../models/Dispute.php';
        $disputeModel = new Dispute($this->conn);

        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            if (!$this->orderReachedPaid($orderId, (string)$order['status'])) {
                continue;
            }

            if (!$this->wallet->hasTransaction("escrow_in:order:{$orderId}")) {
                if ($this->creditEscrow($sellerUsername, $orderId, (float)$order['amount'], 'system_reconcile')) {
                    $fixed++;
                }
            }

            $status = (string)$order['status'];
            if ($status === OrderStateMachine::COMPLETED) {
                $fixed += $this->reconcileCompletedSellerOrder($order, $sellerUsername, $disputeModel);
            } elseif ($status === OrderStateMachine::CANCELLED) {
                if (!$this->orderHadEscrowFunding($orderId)) {
                    continue;
                }
                if (!$this->wallet->hasTransaction("refund:order:{$orderId}")) {
                    if ($this->refundBuyer((string)$order['buyer_name'], $sellerUsername, $orderId, (float)$order['amount'], 'system_reconcile')) {
                        $fixed++;
                    }
                }
            }
        }

        return $fixed;
    }

    private function reconcileBuyerOrders(string $buyerUsername): int {
        $fixed = 0;
        $stmt = $this->conn->prepare(
            "SELECT id, status, amount, seller_name FROM orders WHERE buyer_name = ? AND status = ? ORDER BY id ASC"
        );
        $cancelled = OrderStateMachine::CANCELLED;
        $stmt->bind_param('ss', $buyerUsername, $cancelled);
        $stmt->execute();
        $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            if (!$this->orderHadEscrowFunding($orderId)) {
                continue;
            }
            if ($this->wallet->hasTransaction("refund:order:{$orderId}")) {
                continue;
            }
            if ($this->refundBuyer($buyerUsername, (string)$order['seller_name'], $orderId, (float)$order['amount'], 'system_reconcile')) {
                $fixed++;
            }
        }

        return $fixed;
    }

    private function reconcileCompletedSellerOrder(array $order, string $sellerUsername, Dispute $disputeModel): int {
        $orderId = (int)$order['id'];

        if ($this->wallet->hasTransaction("escrow_release:order:{$orderId}")
            || $this->wallet->hasTransaction("split_release:order:{$orderId}")
            || $this->wallet->hasTransaction("split_refund:order:{$orderId}")) {
            return 0;
        }

        $dispute = $disputeModel->findByOrderId($orderId);
        if ($dispute && ($dispute['resolution'] ?? '') === 'SPLIT') {
            $buyerAmt = (float)($dispute['buyer_refund_amount'] ?? 0);
            $sellerAmt = (float)($dispute['seller_payout_amount'] ?? 0);
            if ($buyerAmt > 0 || $sellerAmt > 0) {
                return $this->splitDisputeResolution(
                    (string)$order['buyer_name'],
                    $sellerUsername,
                    $orderId,
                    $buyerAmt,
                    $sellerAmt,
                    'system_reconcile'
                ) ? 1 : 0;
            }
        }

        return $this->releaseToSeller($sellerUsername, $orderId, (float)$order['amount'], 'system_reconcile') ? 1 : 0;
    }

    private function orderReachedPaid(int $orderId, string $currentStatus): bool {
        $paidStates = [
            OrderStateMachine::PAID,
            OrderStateMachine::SHIPPED,
            OrderStateMachine::DISPUTE,
            OrderStateMachine::COMPLETED,
        ];
        if (in_array($currentStatus, $paidStates, true)) {
            return true;
        }

        if ($currentStatus === OrderStateMachine::CANCELLED) {
            return $this->orderHadEscrowFunding($orderId) || $this->orderEverPaidInLogs($orderId);
        }

        return $this->orderEverPaidInLogs($orderId);
    }

    private function orderEverPaidInLogs(int $orderId): bool {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM order_status_logs WHERE order_id = ? AND new_status IN ('PAID','SHIPPED','DISPUTE','COMPLETED') LIMIT 1"
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    private function orderHadEscrowFunding(int $orderId): bool {
        return $this->wallet->hasTransaction("escrow_in:order:{$orderId}");
    }

    /**
     * Reverse refund credits that were issued without escrow ever being funded.
     */
    private function cleanupInvalidRefunds(string $username): int {
        $userId = $this->wallet->getUserIdByUsername($username);
        if (!$userId) {
            return 0;
        }

        $fixed = 0;
        $stmt = $this->conn->prepare(
            "SELECT id, order_id, amount, idempotency_key FROM wallet_transactions
             WHERE user_id = ? AND direction = 'credit' AND balance_type = 'available'
             AND type IN ('refund_credit', 'split_refund') AND order_id IS NOT NULL"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            $orderId = (int)$row['order_id'];
            if ($orderId <= 0 || $this->orderHadEscrowFunding($orderId)) {
                continue;
            }
            $reversalKey = 'refund_reversal:order:' . $orderId;
            if ($this->wallet->hasTransaction($reversalKey)) {
                continue;
            }
            if ($this->debitAvailable($username, $orderId, (float)$row['amount'], 'system_reconcile', $reversalKey, 'refund_reversal', "ยกเลิกคืนเงินผิดพลาด ออเดอร์ #{$orderId} (ไม่เคยชำระ)")) {
                $fixed++;
            }
        }

        return $fixed;
    }

    private function applyLedger(string $username, ?int $orderId, float $amount, string $balanceType, string $direction, string $type, string $desc, string $by, string $key): bool {
        $userId = $this->wallet->getUserIdByUsername($username);
        if (!$userId || $amount <= 0) {
            return false;
        }
        $wallet = $this->wallet->getOrCreateWallet($userId);
        $col = $balanceType === 'escrow' ? 'escrow_balance' : 'available_balance';
        $current = (float)$wallet[$col];
        $newBal = $direction === 'credit' ? $current + $amount : max(0, $current - $amount);

        $this->conn->begin_transaction();
        try {
            $upd = $this->conn->prepare("UPDATE wallets SET {$col} = ? WHERE user_id = ?");
            $upd->bind_param('di', $newBal, $userId);
            $upd->execute();
            $this->recordTransaction($userId, $orderId, $amount, $balanceType, $direction, $type, $newBal, $desc, $by, $key);
            $this->conn->commit();
            return true;
        } catch (Throwable $e) {
            $this->conn->rollback();
            error_log('applyLedger: ' . $e->getMessage());
            return false;
        }
    }

    private function creditAvailable(string $username, ?int $orderId, float $amount, string $by, string $key, string $type, string $desc): bool {
        return $this->applyLedger($username, $orderId, $amount, 'available', 'credit', $type, $desc, $by, $key);
    }

    private function debitEscrow(string $username, ?int $orderId, float $amount, string $by, string $key): bool {
        return $this->applyLedger($username, $orderId, $amount, 'escrow', 'debit', 'escrow_debit', "หักเงิน Escrow", $by, $key);
    }

    private function debitAvailable(string $username, ?int $orderId, float $amount, string $by, string $key, string $type, string $desc): bool {
        return $this->applyLedger($username, $orderId, $amount, 'available', 'debit', $type, $desc, $by, $key);
    }

    private function recordTransaction(int $userId, ?int $orderId, float $amount, string $balanceType, string $direction, string $type, float $balanceAfter, string $desc, string $by, string $key): void {
        $wallet = $this->wallet->getOrCreateWallet($userId);
        $walletId = (int)$wallet['id'];
        $oid = $orderId ?? 0;
        $stmt = $this->conn->prepare(
            "INSERT INTO wallet_transactions (wallet_id, user_id, order_id, type, amount, balance_type, direction, balance_after, description, created_by, idempotency_key) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iiisdssdsss', $walletId, $userId, $oid, $type, $amount, $balanceType, $direction, $balanceAfter, $desc, $by, $key);
        if (!$stmt->execute()) {
            throw new RuntimeException('wallet_transactions insert failed: ' . $this->conn->error);
        }
    }

    private function getOrder(int $orderId): ?array {
        $stmt = $this->conn->prepare("SELECT id, buyer_name, seller_name, amount, status FROM orders WHERE id = ?");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
