<?php
// models/OAuthAccount.php

class OAuthAccount {
    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    public function findByProvider(string $provider, string $providerUserId): ?array {
        $sql = 'SELECT * FROM user_oauth_accounts WHERE provider = ? AND provider_user_id = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $provider, $providerUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public function findByUserAndProvider(int $userId, string $provider): ?array {
        $sql = 'SELECT * FROM user_oauth_accounts WHERE user_id = ? AND provider = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $userId, $provider);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public function link(int $userId, string $provider, string $providerUserId, ?string $email): bool {
        $existing = $this->findByProvider($provider, $providerUserId);
        if ($existing) {
            return (int) $existing['user_id'] === $userId;
        }

        $sql = 'INSERT INTO user_oauth_accounts (user_id, provider, provider_user_id, email) VALUES (?, ?, ?, ?)';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('OAuthAccount link failed: ' . $this->conn->error);
            return false;
        }
        $stmt->bind_param('isss', $userId, $provider, $providerUserId, $email);
        return $stmt->execute();
    }
}
