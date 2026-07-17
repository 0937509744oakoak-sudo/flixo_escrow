-- Migration 025: Widen messages.image_path to hold full Cloud Object Storage URLs
-- Chat images move from local filenames to R2/S3 URLs which can be long
-- (public/CDN domain + prefix + 32-char name). VARCHAR(512) leaves ample room.
-- Widening length is a safe, non-destructive change for existing rows.
-- Run: php scripts/run_migration.php 025_widen_image_path_for_cloud_urls.sql

ALTER TABLE messages
    MODIFY COLUMN image_path VARCHAR(512) NULL;
