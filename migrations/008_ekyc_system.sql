-- Phase 3: eKYC sessions and user fields

CREATE TABLE IF NOT EXISTS ekyc_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'manual',
    external_ref VARCHAR(100) NULL COMMENT 'Sumsub applicantId or session ref',
    status VARCHAR(30) NOT NULL DEFAULT 'init',
    review_status VARCHAR(30) NULL,
    review_answer VARCHAR(20) NULL COMMENT 'GREEN, RED, RETRY',
    reject_labels JSON NULL,
    raw_payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ekyc_user (user_id),
    INDEX idx_ekyc_external (external_ref),
    CONSTRAINT fk_ekyc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN ekyc_provider VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER kyc_status;
ALTER TABLE users ADD COLUMN ekyc_applicant_id VARCHAR(100) NULL AFTER ekyc_provider;
ALTER TABLE users ADD COLUMN selfie_image VARCHAR(255) NULL AFTER id_card_image;
ALTER TABLE users ADD COLUMN kyc_verified_at DATETIME NULL AFTER is_verified;
