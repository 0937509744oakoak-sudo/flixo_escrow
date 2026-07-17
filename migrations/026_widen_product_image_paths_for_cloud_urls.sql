-- Migration 026: Widen product image columns to hold full Cloud Object Storage
-- URLs (public/CDN domain + "products/" prefix + 32-char name).
-- Widening length is a safe, non-destructive change for existing rows.
--
-- NOTE: slip columns (orders.slip_image, withdrawal payout_slip) are NOT changed
-- here because slips store a short PRIVATE object key ("slips/<32hex>.jpg", ~45
-- chars) that fits the existing VARCHAR(255).
--
-- Run: php scripts/run_migration.php 026_widen_product_image_paths_for_cloud_urls.sql

ALTER TABLE products
    MODIFY COLUMN image_path VARCHAR(512) NULL;

ALTER TABLE product_images
    MODIFY COLUMN file_path VARCHAR(512) NOT NULL;
