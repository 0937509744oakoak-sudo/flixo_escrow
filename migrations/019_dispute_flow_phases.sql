-- Migration 019: Dispute flow phases — seller response, evidence window, settlement

ALTER TABLE `disputes`
  ADD COLUMN IF NOT EXISTS `seller_response_due_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `evidence_due_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `seller_response` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `seller_responded_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `seller_no_response` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `dispute_comments`
  ADD COLUMN IF NOT EXISTS `comment_type` VARCHAR(32) NOT NULL DEFAULT 'general';

CREATE TABLE IF NOT EXISTS `dispute_settlements` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `dispute_id` INT NOT NULL,
    `proposer` VARCHAR(64) NOT NULL,
    `buyer_amount` DECIMAL(12,2) NOT NULL,
    `seller_amount` DECIMAL(12,2) NOT NULL,
    `message` TEXT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    `responded_by` VARCHAR(64) NULL,
    `responded_at` DATETIME NULL,
    `parent_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_dispute_id` (`dispute_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
