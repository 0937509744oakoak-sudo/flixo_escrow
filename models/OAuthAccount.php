<?php
// models/OAuthAccount.php

class OAuthAccount {
    private $conn;
    private static bool $tableReady = false;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    /** Create oauth table if missing (safe for first deploy without CLI migration). */
    public function ensureTable(): void {
        if (self::$tableReady) {
            return;
        }
        $sql = "CREATE TABLE IF NOT EXISTS user_oauth_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL,
            provider_user_id VARCHAR(191) NOT NULL,
            email VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_oauth_provider_uid (provider, provider_user_id),
            UNIQUE KEY uq_oauth_user_provider (user_id, provider),
            INDEX idx_oauth_user (user_id),
            INDEX idx_oauth_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->conn->query($sql)) {
            throw new RuntimeException('ไม่สามารถเตรียมตาราง OAuth ได้: ' . $this->conn->error);
        }
        self::$tableReady = true;
    }

    public function findByProvider(string $provider, string $providerUserId): ?array {
        $this->ensureTable();
        $sql = 'SELECT * FROM user_oauth_accounts WHERE provider = ? AND provider_user_id = ? LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('OAuthAccount findByProvider prepare: ' . $this->conn->error);
            return null;
        }
        $stmt->bind_param('ss', $provider, $providerUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public function findByUserAndProvider(int $userId, string $provider): ?array {
        $this->ensureTable();
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

    /**
     * Link an OAuth identity to a user. Throws on hard failures.
     */
    public function link(int $userId, string $provider, string $providerUserId, ?string $email): bool {
        $this->ensureTable();
        $email = $email !== null ? trim($email) : '';

        $existing = $this->findByProvider($provider, $providerUserId);
        if ($existing) {
            if ((int) $existing['user_id'] === $userId) {
                return true;
            }
            // Orphaned row (user deleted) — reassign
            $check = $this->conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            if ($check) {
                $oldId = (int) $existing['user_id'];
                $check->bind_param('i', $oldId);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $upd = $this->conn->prepare('UPDATE user_oauth_accounts SET user_id = ?, email = ? WHERE id = ?');
                    if ($upd) {
                        $rowId = (int) $existing['id'];
                        $upd->bind_param('isi', $userId, $email, $rowId);
                        if ($upd->execute()) {
                            return true;
                        }
                    }
                }
            }
            throw new RuntimeException('บัญชี Google นี้ถูกผูกกับผู้ใช้อื่นอยู่แล้ว');
        }

        // User already linked to another Google account?
        $byUser = $this->findByUserAndProvider($userId, $provider);
        if ($byUser) {
            if ($byUser['provider_user_id'] === $providerUserId) {
                return true;
            }
            throw new RuntimeException('บัญชีนี้ผูกกับ Google อื่นอยู่แล้ว');
        }

        $sql = 'INSERT INTO user_oauth_accounts (user_id, provider, provider_user_id, email) VALUES (?, ?, ?, ?)';
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log('OAuthAccount link prepare failed: ' . $this->conn->error);
            throw new RuntimeException('เชื่อมบัญชี Google ไม่สำเร็จ (ฐานข้อมูลยังไม่พร้อม)');
        }
        $stmt->bind_param('isss', $userId, $provider, $providerUserId, $email);
        if (!$stmt->execute()) {
            $err = $stmt->error ?: $this->conn->error;
            error_log('OAuthAccount link execute failed: ' . $err);
            // Race: duplicate insert
            if (stripos($err, 'Duplicate') !== false) {
                $again = $this->findByProvider($provider, $providerUserId);
                if ($again && (int) $again['user_id'] === $userId) {
                    return true;
                }
            }
            throw new RuntimeException('เชื่อมบัญชี Google ไม่สำเร็จ');
        }
        return true;
    }
}
