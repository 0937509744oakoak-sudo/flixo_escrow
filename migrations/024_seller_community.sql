-- 024: Seller community features — follower system + online/last active presence

ALTER TABLE users
    ADD COLUMN last_active_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS user_follows (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    follower_name VARCHAR(64) NOT NULL,
    target_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_follow (follower_name, target_name),
    KEY idx_target (target_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
