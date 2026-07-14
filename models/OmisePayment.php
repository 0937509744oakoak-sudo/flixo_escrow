<?php
// models/OmisePayment.php

class OmisePayment {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    public function record(int $orderId, string $omiseId, string $type, float $amount, int $satang, string $status, ?array $raw = null, ?string $failMsg = null): int {
        $json = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
        $sql = "INSERT INTO omise_payments (order_id, omise_id, payment_type, amount, amount_satang, status, failure_message, raw_response)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), failure_message = VALUES(failure_message), raw_response = VALUES(raw_response), updated_at = NOW()";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('issdisss', $orderId, $omiseId, $type, $amount, $satang, $status, $failMsg, $json);
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    public function updateStatus(string $omiseId, string $status, ?array $raw = null, ?string $failMsg = null): void {
        $json = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $this->conn->prepare("UPDATE omise_payments SET status = ?, failure_message = ?, raw_response = COALESCE(?, raw_response), updated_at = NOW() WHERE omise_id = ?");
        $stmt->bind_param('ssss', $status, $failMsg, $json, $omiseId);
        $stmt->execute();
    }

    public function findByOmiseId(string $omiseId): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM omise_payments WHERE omise_id = ? LIMIT 1");
        $stmt->bind_param('s', $omiseId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function findPendingPromptPayByOrder(int $orderId): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM omise_payments WHERE order_id = ? AND payment_type = 'promptpay' AND status IN ('pending','chargeable') ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
