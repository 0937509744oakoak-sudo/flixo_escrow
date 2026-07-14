-- Migration 012: logistics tracking columns + shipment events

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `courier_code` VARCHAR(32) NULL AFTER `courier`,
  ADD COLUMN IF NOT EXISTS `tracking_url` VARCHAR(512) NULL AFTER `tracking_number`,
  ADD COLUMN IF NOT EXISTS `shipped_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `last_tracking_sync_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `tracking_status` VARCHAR(128) NULL,
  ADD COLUMN IF NOT EXISTS `tracking_status_code` VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS `shipment_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT UNSIGNED NOT NULL,
  `status_code` VARCHAR(32) NOT NULL,
  `status_label` VARCHAR(128) NOT NULL,
  `location` VARCHAR(255) NULL,
  `event_at` DATETIME NOT NULL,
  `source` ENUM('manual','api','webhook') NOT NULL DEFAULT 'manual',
  `raw_payload` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_shipment_order` (`order_id`),
  INDEX `idx_shipment_event_at` (`event_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
