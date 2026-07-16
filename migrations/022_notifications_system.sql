-- Migration 022: Real notifications system (in-app + email + push)
-- Run: php scripts/run_migration.php 022_notifications_system.sql

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'system' COMMENT 'order|dispute|payout|ekyc|system',
    title VARCHAR(150) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    link_url VARCHAR(255) NULL,
    icon VARCHAR(10) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    channels JSON NULL COMMENT 'delivery attempt/result per channel, e.g. {"email":true,"push":false}',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_name, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fallback for environments where a bare legacy `notifications` table
-- (id, user_name, message, link_url, is_read, created_at) already exists.
-- run_migration.php tolerates "Duplicate column" / "already exists" errors.
ALTER TABLE notifications ADD COLUMN type VARCHAR(30) NOT NULL DEFAULT 'system' AFTER user_name;
ALTER TABLE notifications ADD COLUMN title VARCHAR(150) NOT NULL DEFAULT '' AFTER type;
ALTER TABLE notifications ADD COLUMN icon VARCHAR(10) NULL AFTER link_url;
ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER is_read;
ALTER TABLE notifications ADD COLUMN channels JSON NULL AFTER read_at;
ALTER TABLE notifications ADD INDEX idx_notifications_user (user_name, is_read, created_at);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL COMMENT 'sha256(endpoint) — endpoint URLs are too long to index directly',
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY uniq_push_endpoint (endpoint_hash),
    INDEX idx_push_sub_user (user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_name VARCHAR(100) NOT NULL,
    email_enabled TINYINT(1) NOT NULL DEFAULT 1,
    push_enabled TINYINT(1) NOT NULL DEFAULT 1,
    marketing_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_notif_pref_user (user_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
