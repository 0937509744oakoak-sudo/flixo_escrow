-- Migration 015: dispute split resolution columns

ALTER TABLE `disputes`
  ADD COLUMN IF NOT EXISTS `buyer_refund_amount` DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS `seller_payout_amount` DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS `resolved_by` VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS `admin_note` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `sla_due_at` DATETIME NULL;
