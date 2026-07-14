-- Migration 013: PDPA consent withdrawal + data subject requests

ALTER TABLE `user_consents`
  ADD COLUMN IF NOT EXISTS `withdrawn_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `withdrawn_ip` VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS `withdrawn_reason` TEXT NULL;

CREATE TABLE IF NOT EXISTS `data_subject_requests` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `request_type` ENUM('export','delete','rectify','object') NOT NULL,
  `status` ENUM('pending','processing','completed','rejected') NOT NULL DEFAULT 'pending',
  `payload_path` VARCHAR(512) NULL,
  `admin_note` TEXT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  INDEX `idx_dsr_user` (`user_id`),
  INDEX `idx_dsr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
