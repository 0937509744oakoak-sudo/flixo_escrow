-- Omise payment records

CREATE TABLE IF NOT EXISTS omise_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    omise_id VARCHAR(100) NOT NULL COMMENT 'charge_id or source_id',
    payment_type ENUM('card', 'promptpay') NOT NULL DEFAULT 'card',
    amount DECIMAL(12,2) NOT NULL,
    amount_satang INT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    failure_message VARCHAR(255) NULL,
    raw_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_omise_id (omise_id),
    INDEX idx_order (order_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
