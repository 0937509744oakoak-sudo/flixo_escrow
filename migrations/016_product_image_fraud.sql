-- Migration 016: AI product image fraud detection
-- Run: php scripts/run_migration.php 016_product_image_fraud.sql

ALTER TABLE products
    ADD COLUMN image_fraud_status VARCHAR(20) NOT NULL DEFAULT 'skipped'
        COMMENT 'skipped|passed|flagged|rejected|pending' AFTER image_path,
    ADD COLUMN image_fraud_score DECIMAL(5,2) NULL AFTER image_fraud_status,
    ADD COLUMN image_fraud_reasons JSON NULL AFTER image_fraud_score,
    ADD COLUMN image_fraud_checked_at DATETIME NULL AFTER image_fraud_reasons;

CREATE TABLE IF NOT EXISTS product_image_fraud_scans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NULL,
    file_path VARCHAR(255) NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'off',
    verdict VARCHAR(20) NOT NULL DEFAULT 'pass',
    risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    reasons JSON NULL,
    raw_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fraud_product (product_id),
    INDEX idx_fraud_verdict (verdict, created_at),
    CONSTRAINT fk_fraud_scan_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
