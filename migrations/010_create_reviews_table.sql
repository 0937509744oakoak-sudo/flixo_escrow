-- Migration 010: reviews table for buyer ratings after completed orders

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reviewer_name` VARCHAR(100) NOT NULL COMMENT 'ผู้เขียนรีวิว (buyer)',
  `target_name` VARCHAR(100) NOT NULL COMMENT 'ผู้ถูกรีวิว (seller)',
  `rating` TINYINT UNSIGNED NOT NULL COMMENT '1-5 stars',
  `comment` TEXT NOT NULL,
  `order_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_review_order` (`order_id`),
  INDEX `idx_reviews_target` (`target_name`),
  INDEX `idx_reviews_reviewer` (`reviewer_name`),
  INDEX `idx_reviews_created` (`created_at`),
  CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
