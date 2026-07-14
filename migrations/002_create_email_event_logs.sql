-- Migration: create email event logs table for FLIXO

CREATE TABLE IF NOT EXISTS `email_event_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_name` VARCHAR(50) NOT NULL,
  `recipient_email` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `status` ENUM('success', 'failed') NOT NULL DEFAULT 'failed',
  `error_message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`user_name`),
  INDEX (`recipient_email`),
  INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
