-- Migration 017: AI Chat Monitoring — keyword/pattern risk detection
-- Run: php scripts/run_migration.php 017_chat_moderation.sql

ALTER TABLE messages
    ADD COLUMN moderation_status VARCHAR(20) NOT NULL DEFAULT 'clean'
        COMMENT 'clean|flagged|blocked' AFTER image_path,
    ADD COLUMN moderation_score DECIMAL(5,2) NULL AFTER moderation_status,
    ADD COLUMN moderation_reasons JSON NULL AFTER moderation_score;

CREATE TABLE IF NOT EXISTS chat_moderation_scans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NULL,
    sender_name VARCHAR(100) NOT NULL,
    receiver_name VARCHAR(100) NOT NULL,
    content_type VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT 'text|image',
    message_preview VARCHAR(500) NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'regex',
    verdict VARCHAR(20) NOT NULL DEFAULT 'pass',
    risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    matched_patterns JSON NULL,
    reasons JSON NULL,
    raw_response JSON NULL,
    review_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|dismissed|actioned',
    reviewed_by VARCHAR(100) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_mod_message (message_id),
    INDEX idx_chat_mod_verdict (verdict, review_status, created_at),
    INDEX idx_chat_mod_sender (sender_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
