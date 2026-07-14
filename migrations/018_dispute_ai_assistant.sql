-- Migration 018: AI Dispute Assistant analysis storage

CREATE TABLE IF NOT EXISTS dispute_ai_analyses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dispute_id INT NOT NULL,
    requested_by VARCHAR(64) NOT NULL,
    provider VARCHAR(32) NOT NULL DEFAULT 'gemini',
    summary TEXT,
    buyer_fault_pct TINYINT UNSIGNED NULL,
    seller_fault_pct TINYINT UNSIGNED NULL,
    recommended_action VARCHAR(32) NULL COMMENT 'REFUND_BUYER, PAYOUT_SELLER, SPLIT, NEEDS_REVIEW',
    suggested_buyer_refund DECIMAL(12,2) NULL,
    suggested_seller_payout DECIMAL(12,2) NULL,
    confidence TINYINT UNSIGNED NULL,
    key_findings JSON NULL,
    reasoning TEXT NULL,
    raw_response JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed' COMMENT 'completed, error',
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispute_id (dispute_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
