<?php
// includes/WalletService.php — ledger operations for escrow wallet

require_once __DIR__ . '/OrderStateMachine.php';
require_once __DIR__ . '/order_helper.php';
require_once __DIR__ . '/../models/Wallet.php';

class WalletService {
    /** Tolerance for DECIMAL(12,2) values round-tripped through PHP floats. */
    private const EPSILON = 0.005;

    private mysqli $conn;
    private Wallet $wallet;

    /**
     * MySQL implicitly commits an open transaction when START TRANSACTION runs
     * again, so a nested begin_transaction() would quietly make the outer
     * rollback a no-op. Tracking depth lets inner operations join the caller's
     * transaction instead of starting their own.
     */
    private int $txDepth = 0;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        $this->wallet = new Wallet($conn);
    }

    /**
     * Run $fn inside a transaction, committing only at the outermost level.
     * Exceptions roll the whole thing back and propagate to the caller.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withTransaction(callable $fn) {
        if ($this->txDepth > 0) {
            $this->txDepth++;
            try {
                return $fn();
            } finally {
                $this->txDepth--;
            }
        }

        $this->conn->begin_transaction();
        $this->txDepth = 1;
        try {
            $result = $fn();
            $this->conn->commit();
            return $result;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        } finally {
            $this->txDepth = 0;
        }
    }

    /**
     * Read a wallet with a row lock held for the rest of the transaction, so
     * concurrent balance changes queue behind us instead of racing on a value
     * we already read.
     *
     * @return array{id:string, available_balance:string, escrow_balance:string}
     */
    private function lockWallet(int $userId): array {
        $this->wallet->getOrCreateWallet($userId);
        $stmt = $this->conn->prepare('SELECT id, available_balance, escrow_balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            throw new RuntimeException("ไม่พบกระเป๋าเงินของผู้ใช้ #{$userId}");
        }
        return $row;
    }

    private function requireUserId(string $username): int {
        $userId = $this->wallet->getUserIdByUsername($username);
        if (!$userId) {
            throw new RuntimeException("ไม่พบผู้ใช้ {$username}");
        }
        return $userId;
    }

    private function failed(string $operation, Throwable $e, array $context = []): bool {
        ErrorLogger::write('error', "WalletService {$operation}: " . $e->getMessage(), $context);
        return false;
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
        try {
            return $this->withTransaction(function () use ($sellerUsername, $orderId, $amount, $by, $key) {
                $this->applyLedger($sellerUsername, $orderId, $amount, 'escrow', 'credit', 'escrow_in', "รับเงินเข้า Escrow ออเดอร์ #{$orderId}", $by, $key);
                return true;
            });
        } catch (Throwable $e) {
            return $this->failed('creditEscrow', $e, ['order_id' => $orderId, 'seller' => $sellerUsername]);
        }
    }

    public function releaseToSeller(string $sellerUsername, int $orderId, float $amount, string $by): bool {
        $key = "escrow_release:order:{$orderId}";
        if ($this->wallet->hasTransaction($key)) {
            return true;
        }
        try {
            return $this->withTransaction(function () use ($sellerUsername, $orderId, $amount, $by, $key) {
                $userId = $this->requireUserId($sellerUsername);
                $wallet = $this->lockWallet($userId);
                $releaseAmount = min($amount, (float) $wallet['escrow_balance']);
                if ($releaseAmount <= 0) {
                    throw new RuntimeException("ไม่มียอดใน Escrow สำหรับออเดอร์ #{$orderId}");
                }

                $this->applyLedger($sellerUsername, $orderId, $releaseAmount, 'escrow', 'debit', 'escrow_debit', 'หักเงิน Escrow', $by, "escrow_release_debit:order:{$orderId}");
                $this->applyLedger($sellerUsername, $orderId, $releaseAmount, 'available', 'credit', 'escrow_release', "ปล่อยเงินจาก Escrow ออเดอร์ #{$orderId}", $by, $key);

                $upd = $this->conn->prepare('UPDATE wallets SET total_earned = total_earned + ? WHERE user_id = ?');
                $upd->bind_param('di', $releaseAmount, $userId);
                $upd->execute();
                return true;
            });
        } catch (Throwable $e) {
            return $this->failed('releaseToSeller', $e, ['order_id' => $orderId, 'seller' => $sellerUsername]);
        }
    }

    public function refundBuyer(string $buyerUsername, string $sellerUsername, int $orderId, float $amount, string $by): bool {
        $key = "refund:order:{$orderId}";
        if ($this->wallet->hasTransaction($key)) {
            return true;
        }
        try {
            return $this->withTransaction(function () use ($buyerUsername, $sellerUsername, $orderId, $amount, $by, $key) {
                $sellerId = $this->wallet->getUserIdByUsername($sellerUsername);
                if ($sellerId) {
                    $wallet = $this->lockWallet($sellerId);
                    $deduct = min($amount, (float) $wallet['escrow_balance']);
                    if ($deduct > 0) {
                        $this->applyLedger($sellerUsername, $orderId, $deduct, 'escrow', 'debit', 'escrow_debit', 'หักเงิน Escrow', $by, "refund_escrow_debit:order:{$orderId}");
                    }
                }
                $this->applyLedger($buyerUsername, $orderId, $amount, 'available', 'credit', 'refund_credit', "คืนเงินออเดอร์ #{$orderId}", $by, $key);
                return true;
            });
        } catch (Throwable $e) {
            return $this->failed('refundBuyer', $e, ['order_id' => $orderId, 'buyer' => $buyerUsername]);
        }
    }

    public function requestWithdrawal(string $username, float $amount, int $bankAccountId): array {
        if ($amount < 100) {
            return ['ok' => false, 'error' => 'ถอนขั้นต่ำ ฿100'];
        }

        try {
            $requestId = $this->withTransaction(function () use ($username, $amount, $bankAccountId) {
                $userId = $this->requireUserId($username);

                // Lock first: the balance check and the debit have to see the
                // same row, or two concurrent requests can both withdraw it.
                $wallet = $this->lockWallet($userId);
                if ($amount > (float) $wallet['available_balance'] + self::EPSILON) {
                    throw new RuntimeException('ยอดเงินไม่เพียงพอ');
                }

                $stmt = $this->conn->prepare('SELECT id FROM user_bank_accounts WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $bankAccountId, $userId);
                $stmt->execute();
                if (!$stmt->get_result()->fetch_assoc()) {
                    throw new RuntimeException('ไม่พบบัญชีธนาคาร');
                }

                // Insert before debiting so the hold can key off the request id.
                // A random key (uniqid) would make every retry look like new
                // work to the idempotency constraint.
                $ins = $this->conn->prepare(
                    'INSERT INTO withdrawal_requests (user_id, wallet_id, bank_account_id, amount) VALUES (?, ?, ?, ?)'
                );
                $walletId = (int) $wallet['id'];
                $ins->bind_param('iiid', $userId, $walletId, $bankAccountId, $amount);
                $ins->execute();
                $requestId = (int) $this->conn->insert_id;

                $this->applyLedger($username, null, $amount, 'available', 'debit', 'withdrawal_hold', 'ระงับเงินรอถอน', $username, "withdraw_hold:req:{$requestId}");
                return $requestId;
            });

            return ['ok' => true, 'request_id' => $requestId];
        } catch (Throwable $e) {
            ErrorLogger::write('error', 'WalletService requestWithdrawal: ' . $e->getMessage(), ['username' => $username, 'amount' => $amount]);
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

        try {
            $slip = $payoutSlip !== null && $payoutSlip !== '' ? $payoutSlip : null;
            $this->withTransaction(function () use ($requestId, $userId, $amount, $adminUser, $adminNote, $slip, $username) {
                $note = trim($adminNote);
                // Only claim a request that is still pending, so two admins
                // clicking approve cannot both pay it out.
                $upd = $this->conn->prepare(
                    "UPDATE withdrawal_requests
                     SET status = 'completed', processed_by = ?, processed_at = NOW(),
                         payout_slip = COALESCE(?, payout_slip),
                         admin_note = IF(? = '', admin_note, ?)
                     WHERE id = ? AND status = 'pending'"
                );
                $upd->bind_param('ssssi', $adminUser, $slip, $note, $note, $requestId);
                $upd->execute();
                if ($upd->affected_rows !== 1) {
                    throw new RuntimeException("คำขอถอน #{$requestId} ถูกดำเนินการไปแล้ว");
                }

                // The money left available_balance when the hold was placed;
                // this row only records the payout, so the balance is unchanged.
                $wallet = $this->lockWallet($userId);
                $key = "withdraw_complete:req:{$requestId}";
                $this->recordTransaction(
                    (int) $wallet['id'],
                    $userId,
                    null,
                    $amount,
                    'available',
                    'debit',
                    'withdrawal_complete',
                    (float) $wallet['available_balance'],
                    "ถอนเงินสำเร็จ #{$requestId}",
                    $adminUser,
                    $key
                );

                $wUpd = $this->conn->prepare('UPDATE wallets SET total_withdrawn = total_withdrawn + ? WHERE user_id = ?');
                $wUpd->bind_param('di', $amount, $userId);
                $wUpd->execute();

                $meta = json_encode(['withdrawal_id' => $requestId, 'username' => $username, 'payout_slip' => $slip]);
                $tx = $this->conn->prepare("INSERT INTO transaction_logs (order_id, type, amount, currency, meta) VALUES (NULL, 'withdrawal_payout', ?, 'THB', ?)");
                $tx->bind_param('ds', $amount, $meta);
                $tx->execute();
            });

            if ($username !== '' && function_exists('createNotification')) {
                $msg = '✅ ถอนเงิน ฿' . number_format($amount, 2) . ' สำเร็จแล้ว';
                if ($slip) {
                    $msg .= ' — ดูสลิปการโอนได้ที่กระเป๋าเงิน';
                }
                createNotification($this->conn, $username, $msg, 'earnings.php');
            }
            return true;
        } catch (Throwable $e) {
            return $this->failed('completeWithdrawal', $e, ['request_id' => $requestId]);
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
        try {
            $this->withTransaction(function () use ($requestId, $adminUser, $note, $req) {
                $upd = $this->conn->prepare(
                    "UPDATE withdrawal_requests SET status = 'rejected', admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ? AND status = 'pending'"
                );
                $upd->bind_param('ssi', $note, $adminUser, $requestId);
                $upd->execute();
                if ($upd->affected_rows !== 1) {
                    throw new RuntimeException("คำขอถอน #{$requestId} ถูกดำเนินการไปแล้ว");
                }

                $key = "withdraw_reject:req:{$requestId}";
                $this->applyLedger($req['username'], null, (float) $req['amount'], 'available', 'credit', 'withdrawal_reject', 'คืนเงินจากคำขอถอนที่ถูกปฏิเสธ', $adminUser, $key);
            });

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
            return $this->failed('rejectWithdrawal', $e, ['request_id' => $requestId]);
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

        try {
            return $this->withTransaction(function () use ($buyerUsername, $sellerUsername, $orderId, $buyerAmount, $sellerAmount, $by, $keyBuyer, $keySeller) {
                $sellerId = $this->wallet->getUserIdByUsername($sellerUsername);
                if ($sellerId) {
                    $wallet = $this->lockWallet($sellerId);
                    // Escrow may hold less than the ruling awards (partial
                    // funding, earlier refund); scale both sides so the split
                    // still adds up to exactly what is there.
                    $total = min($buyerAmount + $sellerAmount, (float) $wallet['escrow_balance']);
                    if ($total <= 0) {
                        throw new RuntimeException('ไม่มียอดใน Escrow');
                    }
                    $ratio = $total / ($buyerAmount + $sellerAmount);
                    $buyerAmount = round($buyerAmount * $ratio, 2);
                    $sellerAmount = round($sellerAmount * $ratio, 2);
                    $this->applyLedger($sellerUsername, $orderId, $total, 'escrow', 'debit', 'escrow_debit', 'หักเงิน Escrow', $by, "split_escrow_debit:order:{$orderId}");
                }
                if ($buyerAmount > 0) {
                    $this->applyLedger($buyerUsername, $orderId, $buyerAmount, 'available', 'credit', 'split_refund', "คืนเงินบางส่วนออเดอร์ #{$orderId}", $by, $keyBuyer);
                }
                if ($sellerAmount > 0) {
                    $this->applyLedger($sellerUsername, $orderId, $sellerAmount, 'available', 'credit', 'split_release', "ปล่อยเงินบางส่วนออเดอร์ #{$orderId}", $by, $keySeller);
                    if ($sellerId) {
                        $upd = $this->conn->prepare('UPDATE wallets SET total_earned = total_earned + ? WHERE user_id = ?');
                        $upd->bind_param('di', $sellerAmount, $sellerId);
                        $upd->execute();
                    }
                }
                return true;
            });
        } catch (Throwable $e) {
            return $this->failed('splitDisputeResolution', $e, ['order_id' => $orderId]);
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

            try {
                $this->withTransaction(function () use ($username, $userId, $orderId, $row, $reversalKey) {
                    // Repair path: the user may already have spent part of an
                    // erroneous refund, so claw back only what is still there
                    // rather than aborting and leaving the whole amount.
                    $wallet = $this->lockWallet($userId);
                    $reversible = min((float) $row['amount'], (float) $wallet['available_balance']);
                    if ($reversible <= 0) {
                        throw new RuntimeException('ยอดคงเหลือไม่พอสำหรับการยกเลิกคืนเงิน');
                    }
                    $this->applyLedger($username, $orderId, $reversible, 'available', 'debit', 'refund_reversal', "ยกเลิกคืนเงินผิดพลาด ออเดอร์ #{$orderId} (ไม่เคยชำระ)", 'system_reconcile', $reversalKey);
                });
                $fixed++;
            } catch (Throwable $e) {
                $this->failed('cleanupInvalidRefunds', $e, ['order_id' => $orderId, 'username' => $username]);
            }
        }

        return $fixed;
    }

    /**
     * Apply a single balance movement plus its ledger row.
     *
     * Must be called inside withTransaction(). The wallet row is locked before
     * being read, and the balance is written relative to its stored value
     * (`col = col - ?`) rather than as a total computed beforehand — otherwise
     * two concurrent debits both read the same balance and the second one
     * silently erases the first.
     *
     * Throws rather than returning false so a failure aborts the whole
     * enclosing operation instead of leaving half a transfer applied.
     */
    private function applyLedger(string $username, ?int $orderId, float $amount, string $balanceType, string $direction, string $type, string $desc, string $by, string $key): void {
        if ($amount <= 0) {
            throw new RuntimeException('จำนวนเงินต้องมากกว่า 0');
        }
        $userId = $this->requireUserId($username);
        $wallet = $this->lockWallet($userId);

        $col = $balanceType === 'escrow' ? 'escrow_balance' : 'available_balance';
        $current = (float) $wallet[$col];

        if ($direction === 'debit' && $current + self::EPSILON < $amount) {
            throw new RuntimeException(sprintf(
                'ยอดคงเหลือไม่พอ (%s ของ %s: %.2f < %.2f)', $col, $username, $current, $amount
            ));
        }
        $newBalance = $direction === 'credit' ? $current + $amount : $current - $amount;

        $operator = $direction === 'credit' ? '+' : '-';
        $upd = $this->conn->prepare("UPDATE wallets SET {$col} = {$col} {$operator} ? WHERE user_id = ?");
        $upd->bind_param('di', $amount, $userId);
        $upd->execute();

        $this->recordTransaction((int) $wallet['id'], $userId, $orderId, $amount, $balanceType, $direction, $type, $newBalance, $desc, $by, $key);
    }

    private function recordTransaction(int $walletId, int $userId, ?int $orderId, float $amount, string $balanceType, string $direction, string $type, float $balanceAfter, string $desc, string $by, string $key): void {
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
