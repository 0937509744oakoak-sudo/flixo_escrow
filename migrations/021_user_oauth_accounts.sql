-- Migration 021: OAuth linked accounts (Google Sign-In, etc.)
-- Run: php scripts/run_migration.php 021_user_oauth_accounts.sql

CREATE TABLE IF NOT EXISTS user_oauth_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL COMMENT 'google|facebook|line',
    provider_user_id VARCHAR(191) NOT NULL,
    email VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_provider_uid (provider, provider_user_id),
    UNIQUE KEY uq_oauth_user_provider (user_id, provider),
    INDEX idx_oauth_user (user_id),
    INDEX idx_oauth_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
