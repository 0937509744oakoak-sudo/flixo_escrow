-- 027_login_attempts.sql — throttle credential stuffing on login and password reset
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(32) NOT NULL DEFAULT 'login',
    identifier VARCHAR(191) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_window (action, identifier, successful, created_at),
    INDEX idx_ip_window (action, ip, successful, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
