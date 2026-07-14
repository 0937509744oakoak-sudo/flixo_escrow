-- Migration 011: align legacy reviews table with Phase 0 schema

ALTER TABLE `reviews`
  MODIFY COLUMN `reviewer_name` VARCHAR(100) NOT NULL COMMENT 'ผู้เขียนรีวิว (buyer)',
  MODIFY COLUMN `target_name` VARCHAR(100) NOT NULL COMMENT 'ผู้ถูกรีวิว (seller)',
  MODIFY COLUMN `rating` TINYINT UNSIGNED NOT NULL COMMENT '1-5 stars',
  MODIFY COLUMN `comment` TEXT NOT NULL,
  MODIFY COLUMN `order_id` INT UNSIGNED NOT NULL,
  MODIFY COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `reviews`
  ADD UNIQUE KEY `uniq_review_order` (`order_id`),
  ADD INDEX `idx_reviews_target` (`target_name`),
  ADD INDEX `idx_reviews_reviewer` (`reviewer_name`),
  ADD INDEX `idx_reviews_created` (`created_at`);
