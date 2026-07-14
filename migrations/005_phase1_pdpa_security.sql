-- Phase 1: PDPA consent tracking + PII access audit logs

CREATE TABLE IF NOT EXISTS user_consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(64) NOT NULL COMMENT 'terms_of_service, privacy_policy, kyc_data_collection',
    version VARCHAR(16) NOT NULL DEFAULT '1.0',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    consented_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_consent (user_id, consent_type),
    INDEX idx_consented_at (consented_at),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pii_audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_username VARCHAR(64) NOT NULL,
    target_username VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL COMMENT 'view_kyc_image, view_user_pii',
    resource_type VARCHAR(64) NOT NULL DEFAULT 'kyc_image',
    resource_id VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_username),
    INDEX idx_target (target_username),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
