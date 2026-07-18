<?php
// models/Wallet.php

require_once __DIR__ . '/../includes/PiiEncryption.php';

class Wallet {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    public function getUserIdByUsername(string $username): ?int {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    public function getOrCreateWallet(int $userId): array {
        $stmt = $this->conn->prepare("SELECT * FROM wallets WHERE user_id = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('wallets table missing or unreadable: ' . $this->conn->error);
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $wallet = $stmt->get_result()->fetch_assoc();
        if ($wallet) {
            return $wallet;
        }
        $ins = $this->conn->prepare("INSERT INTO wallets (user_id) VALUES (?)");
        if (!$ins) {
            throw new RuntimeException('Cannot create wallet: ' . $this->conn->error);
        }
        $ins->bind_param('i', $userId);
        $ins->execute();
        return [
            'id' => (int)$this->conn->insert_id,
            'user_id' => $userId,
            'available_balance' => 0,
            'escrow_balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
        ];
    }

    public function getWalletByUsername(string $username): ?array {
        $userId = $this->getUserIdByUsername($username);
        if (!$userId) {
            return null;
        }
        return $this->getOrCreateWallet($userId);
    }

    public function hasTransaction(string $idempotencyKey): bool {
        $stmt = $this->conn->prepare("SELECT id FROM wallet_transactions WHERE idempotency_key = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('wallet_transactions table missing or unreadable: ' . $this->conn->error);
        }
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function getTransactions(int $userId, int $limit = 30): array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function saveBankAccount(int $userId, string $bankCode, string $bankName, string $accountNumber, string $accountName, bool $setDefault = true): int {
        $enc = PiiEncryption::encrypt($accountNumber);
        if ($setDefault) {
            $this->conn->query("UPDATE user_bank_accounts SET is_default = 0 WHERE user_id = {$userId}");
        }
        $stmt = $this->conn->prepare(
            "INSERT INTO user_bank_accounts (user_id, bank_code, bank_name, account_number_enc, account_name, is_default) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $def = $setDefault ? 1 : 0;
        $stmt->bind_param('issssi', $userId, $bankCode, $bankName, $enc, $accountName, $def);
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    public function getBankAccounts(int $userId): array {
        $stmt = $this->conn->prepare("SELECT * FROM user_bank_accounts WHERE user_id = ? ORDER BY is_default DESC, id DESC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $row['account_number_masked'] = $this->maskAccount(PiiEncryption::decrypt($row['account_number_enc']));
        }
        return $rows;
    }

    public function getDefaultBankAccount(int $userId): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM user_bank_accounts WHERE user_id = ? ORDER BY is_default DESC, id DESC LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $row['account_number'] = PiiEncryption::decrypt($row['account_number_enc']);
            $row['account_number_masked'] = $this->maskAccount($row['account_number']);
        }
        return $row ?: null;
    }

    private function maskAccount(string $num): string {
        $len = strlen($num);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($num, -4);
    }

    public function ensurePayoutSlipColumn(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $check = $this->conn->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'payout_slip'");
        if ($check && $check->num_rows === 0) {
            $this->conn->query("ALTER TABLE withdrawal_requests ADD COLUMN payout_slip VARCHAR(255) NULL AFTER admin_note");
        }
    }

    public function getPendingWithdrawals(int $userId): array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM withdrawal_requests WHERE user_id = ? AND status IN ('pending','processing') ORDER BY created_at DESC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getUserWithdrawals(int $userId, int $limit = 20): array {
        $this->ensurePayoutSlipColumn();
        $stmt = $this->conn->prepare(
            "SELECT w.*, b.bank_name, b.account_name
             FROM withdrawal_requests w
             JOIN user_bank_accounts b ON b.id = w.bank_account_id
             WHERE w.user_id = ?
             ORDER BY w.created_at DESC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function findWithdrawalById(int $requestId): ?array {
        $this->ensurePayoutSlipColumn();
        $stmt = $this->conn->prepare(
            "SELECT w.*, u.username
             FROM withdrawal_requests w
             JOIN users u ON u.id = w.user_id
             WHERE w.id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * @return array{from_sales: float, from_refunds: float, available: float}
     */
    public function getAvailableBreakdown(int $userId): array {
        $wallet = $this->getOrCreateWallet($userId);
        $stmt = $this->conn->prepare(
            "SELECT type, direction, amount FROM wallet_transactions WHERE user_id = ? AND balance_type = 'available'"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $fromSales = 0.0;
        $fromRefunds = 0.0;
        $salesTypes = ['escrow_release', 'split_release'];
        $refundTypes = ['refund_credit', 'split_refund', 'refund_reversal'];

        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $sign = ($row['direction'] === 'credit') ? 1 : -1;
            $type = (string)$row['type'];
            if (in_array($type, $salesTypes, true)) {
                $fromSales += $amount * $sign;
            } elseif (in_array($type, $refundTypes, true)) {
                $fromRefunds += $amount * $sign;
            }
        }

        return [
            'from_sales' => max(0, round($fromSales, 2)),
            'from_refunds' => max(0, round($fromRefunds, 2)),
            'available' => (float)$wallet['available_balance'],
        ];
    }

    public function getAllWithdrawals(int $limit = 50, bool $revealAccount = false): array {
        $this->ensurePayoutSlipColumn();
        $sql = "SELECT w.*, u.username, b.bank_name, b.bank_code, b.account_name, b.account_number_enc
                FROM withdrawal_requests w
                JOIN users u ON u.id = w.user_id
                JOIN user_bank_accounts b ON b.id = w.bank_account_id
                ORDER BY w.created_at DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $plain = PiiEncryption::decrypt($row['account_number_enc']);
            $row['account_number_masked'] = $this->maskAccount($plain);
            if ($revealAccount) {
                $row['account_number'] = $plain;
            }
            unset($row['account_number_enc']);
        }
        return $rows;
    }
}
