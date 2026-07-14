-- Migration 004: Product images gallery + category
-- Run: mysql -u root -p flixo_db < migrations/004_create_product_images.sql

CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_images_product (product_id),
    INDEX idx_product_images_sort (product_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Category column (ignore error if column already exists)
ALTER TABLE products
    ADD COLUMN category VARCHAR(64) NULL DEFAULT NULL AFTER product_name;

-- Backfill gallery rows from legacy image_path
INSERT INTO product_images (product_id, file_path, sort_order, is_primary)
SELECT p.id, p.image_path, 0, 1
FROM products p
LEFT JOIN product_images pi ON pi.product_id = p.id
WHERE p.image_path IS NOT NULL
  AND TRIM(p.image_path) <> ''
  AND pi.id IS NULL;
