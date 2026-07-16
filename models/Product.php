<?php
// models/Product.php

class Product {
    public const MAX_IMAGES = 4;
    public const UPLOAD_SUBDIR = 'uploads/products/';

    private $conn;

    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }

    private function sellerMetaSelect(): string {
        return "(SELECT AVG(rating) FROM reviews WHERE target_name = p.seller_name) AS seller_rating,
                (SELECT is_verified FROM users WHERE username = p.seller_name LIMIT 1) AS seller_verified,
                (SELECT COALESCE(NULLIF(TRIM(u.real_name), ''), p.seller_name) FROM users u WHERE u.username = p.seller_name LIMIT 1) AS seller_display_name";
    }

    /**
     * @deprecated Use createProduct() instead
     */
    public function create($seller, $name, $price, $description, $image) {
        return $this->createProduct($seller, $name, null, $price, $description, [$image]) > 0;
    }

    /**
     * สร้างสินค้าพร้อมแกลเลอรีรูป (transaction)
     */
    public function createProduct($seller, $name, $category, $price, $description, array $imagePaths, ?array $fraudMeta = null) {
        $imagePaths = array_values(array_filter($imagePaths));
        if (empty($imagePaths)) {
            throw new InvalidArgumentException('ต้องมีรูปภาพอย่างน้อย 1 รูป');
        }
        if (count($imagePaths) > self::MAX_IMAGES) {
            throw new InvalidArgumentException('อัปโหลดได้สูงสุด ' . self::MAX_IMAGES . ' รูป');
        }

        $primaryImage = $imagePaths[0];
        $category = ($category !== null && $category !== '') ? $category : null;

        $this->conn->begin_transaction();

        try {
            $fraudStatus = $fraudMeta['status'] ?? 'skipped';
            $fraudScore = isset($fraudMeta['risk_score']) ? (float)$fraudMeta['risk_score'] : null;
            $fraudReasons = !empty($fraudMeta['reasons']) ? json_encode($fraudMeta['reasons'], JSON_UNESCAPED_UNICODE) : null;
            $hasFraud = $this->hasFraudStatusColumn();

            $fraudScoreValue = $fraudScore ?? 0.0;

            if ($this->hasCategoryColumn() && $hasFraud) {
                $sql = "INSERT INTO products (seller_name, product_name, category, price, description, image_path, image_fraud_status, image_fraud_score, image_fraud_reasons, image_fraud_checked_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('sssdsssds', $seller, $name, $category, $price, $description, $primaryImage, $fraudStatus, $fraudScoreValue, $fraudReasons);
            } elseif ($this->hasCategoryColumn()) {
                $sql = "INSERT INTO products (seller_name, product_name, category, price, description, image_path)
                        VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('sssdss', $seller, $name, $category, $price, $description, $primaryImage);
            } elseif ($hasFraud) {
                if ($category) {
                    $description = 'หมวดหมู่: ' . $category . ($description !== '' ? "\n\n" . $description : '');
                }
                $sql = "INSERT INTO products (seller_name, product_name, price, description, image_path, image_fraud_status, image_fraud_score, image_fraud_reasons, image_fraud_checked_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('ssdsssds', $seller, $name, $price, $description, $primaryImage, $fraudStatus, $fraudScoreValue, $fraudReasons);
            } else {
                if ($category) {
                    $description = 'หมวดหมู่: ' . $category . ($description !== '' ? "\n\n" . $description : '');
                }
                $sql = "INSERT INTO products (seller_name, product_name, price, description, image_path)
                        VALUES (?, ?, ?, ?, ?)";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('ssdss', $seller, $name, $price, $description, $primaryImage);
            }

            if (!$stmt->execute()) {
                throw new RuntimeException('ไม่สามารถบันทึกสินค้าได้');
            }

            $productId = (int) $this->conn->insert_id;

            if ($this->hasImagesTable()) {
                $this->insertImages($productId, $imagePaths);
            }

            $this->conn->commit();
            return $productId;
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    private function insertImages($productId, array $imagePaths) {
        $sql = "INSERT INTO product_images (product_id, file_path, sort_order, is_primary) VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($imagePaths as $index => $path) {
            $sortOrder = $index;
            $isPrimary = $index === 0 ? 1 : 0;
            $stmt->bind_param('isii', $productId, $path, $sortOrder, $isPrimary);
            if (!$stmt->execute()) {
                throw new RuntimeException('ไม่สามารถบันทึกรูปภาพสินค้าได้');
            }
        }
    }

    public function getById($id) {
        $sellerMeta = $this->sellerMetaSelect();
        $sql = "SELECT p.*, $sellerMeta FROM products p WHERE p.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if ($product) {
            $product['images'] = $this->getImages($id);
        }

        return $product;
    }

    /**
     * ดึงรูปทั้งหมดของสินค้า เรียงตาม sort_order
     */
    public function getImages($productId) {
        if (!$this->hasImagesTable()) {
            return $this->legacyImagesFromProduct($productId);
        }

        $sql = "SELECT id, file_path, sort_order, is_primary
                FROM product_images
                WHERE product_id = ?
                ORDER BY sort_order ASC, id ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();

        $images = [];
        while ($row = $result->fetch_assoc()) {
            $images[] = $row;
        }

        if (empty($images)) {
            return $this->legacyImagesFromProduct($productId);
        }

        return $images;
    }

    private function legacyImagesFromProduct($productId) {
        $sql = "SELECT image_path FROM products WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || empty($row['image_path'])) {
            return [];
        }

        return [[
            'id' => 0,
            'file_path' => $row['image_path'],
            'sort_order' => 0,
            'is_primary' => 1,
        ]];
    }

    public function getAll($limit = 24, $offset = 0) {
        $sellerMeta = $this->sellerMetaSelect();
        $fraudWhere = $this->publicListingWhere('p');
        $sql = "SELECT p.*, $sellerMeta
                FROM products p
                WHERE 1=1 {$fraudWhere}
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countAll(): int {
        $fraudWhere = $this->publicListingWhere('p');
        $result = $this->conn->query("SELECT COUNT(*) AS cnt FROM products p WHERE 1=1 {$fraudWhere}");
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    }

    public function searchByKeyword($query, $limit = 24, $offset = 0) {
        $search = '%' . $query . '%';
        $sellerMeta = $this->sellerMetaSelect();
        $fraudWhere = $this->publicListingWhere('p');
        if ($this->hasCategoryColumn()) {
            $sql = "SELECT p.*, $sellerMeta
                    FROM products p
                    WHERE (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ? OR p.category LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))
                       {$fraudWhere}
                    ORDER BY p.id DESC
                    LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('sssssii', $search, $search, $search, $search, $search, $limit, $offset);
        } else {
            $sql = "SELECT p.*, $sellerMeta
                    FROM products p
                    WHERE (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))
                       {$fraudWhere}
                    ORDER BY p.id DESC
                    LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('ssssii', $search, $search, $search, $search, $limit, $offset);
        }
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countSearch(string $query): int {
        return $this->countFiltered('', $query);
    }

    /**
     * Catalog listing with optional category, sort, and keyword search.
     */
    public function getFiltered(int $limit = 24, int $offset = 0, string $category = '', string $sort = 'newest', string $search = '') {
        $sellerMeta = $this->sellerMetaSelect();
        $fraudWhere = $this->publicListingWhere('p');
        $where = '1=1' . $fraudWhere;
        $types = '';
        $params = [];

        $search = trim($search);
        $category = trim($category);

        if ($search !== '') {
            $like = '%' . $search . '%';
            if ($this->hasCategoryColumn()) {
                $where .= ' AND (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ? OR p.category LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))';
                $types .= 'sssss';
                array_push($params, $like, $like, $like, $like, $like);
            } else {
                $where .= ' AND (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))';
                $types .= 'ssss';
                array_push($params, $like, $like, $like, $like);
            }
        }

        if ($category !== '' && $this->hasCategoryColumn()) {
            $where .= ' AND p.category = ?';
            $types .= 's';
            $params[] = $category;
        }

        $orderBy = match ($sort) {
            'price_asc' => 'p.price ASC, p.id DESC',
            'price_desc' => 'p.price DESC, p.id DESC',
            default => 'p.id DESC',
        };

        $sql = "SELECT p.*, $sellerMeta FROM products p WHERE {$where} ORDER BY {$orderBy} LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countFiltered(string $category = '', string $search = ''): int {
        $fraudWhere = $this->publicListingWhere('p');
        $where = '1=1' . $fraudWhere;
        $types = '';
        $params = [];

        $search = trim($search);
        $category = trim($category);

        if ($search !== '') {
            $like = '%' . $search . '%';
            if ($this->hasCategoryColumn()) {
                $where .= ' AND (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ? OR p.category LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))';
                $types .= 'sssss';
                array_push($params, $like, $like, $like, $like, $like);
            } else {
                $where .= ' AND (p.product_name LIKE ? OR p.description LIKE ? OR p.seller_name LIKE ?
                       OR EXISTS (SELECT 1 FROM users u WHERE u.username = p.seller_name AND u.real_name LIKE ?))';
                $types .= 'ssss';
                array_push($params, $like, $like, $like, $like);
            }
        }

        if ($category !== '' && $this->hasCategoryColumn()) {
            $where .= ' AND p.category = ?';
            $types .= 's';
            $params[] = $category;
        }

        $sql = "SELECT COUNT(*) AS cnt FROM products p WHERE {$where}";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int) ($row['cnt'] ?? 0);
    }

    public function getBySeller($seller, $limit = 12) {
        $sql = "SELECT * FROM products WHERE seller_name = ? ORDER BY id DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('si', $seller, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function delete($id, $seller) {
        if (!$this->isOwner($id, $seller)) {
            return false;
        }

        $imagePaths = $this->getImageFilePaths($id);

        $sql = "DELETE FROM products WHERE id = ? AND seller_name = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('is', $id, $seller);
        $deleted = $stmt->execute();

        if ($deleted) {
            foreach ($imagePaths as $path) {
                deleteProductImageFile($path);
            }
        }

        return $deleted;
    }

    private function getImageFilePaths($productId) {
        $paths = [];
        foreach ($this->getImages($productId) as $img) {
            if (!empty($img['file_path'])) {
                $paths[] = $img['file_path'];
            }
        }
        return array_unique($paths);
    }

    private function isOwner($id, $seller) {
        $sql = "SELECT id FROM products WHERE id = ? AND seller_name = ? LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('is', $id, $seller);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    public function update($id, $seller, $name, $price, $description, $imagePath = null) {
        if ($imagePath) {
            $sql = "UPDATE products SET product_name = ?, price = ?, description = ?, image_path = ? WHERE id = ? AND seller_name = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('sdssis', $name, $price, $description, $imagePath, $id, $seller);
        } else {
            $sql = "UPDATE products SET product_name = ?, price = ?, description = ? WHERE id = ? AND seller_name = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param('sdsis', $name, $price, $description, $id, $seller);
        }
        return $stmt->execute();
    }

    public static function placeholderUrl(): string {
        $base = defined('ASSETS_URL')
            ? rtrim(ASSETS_URL, '/')
            : (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/assets' : 'assets');
        return $base . '/images/product-placeholder.svg';
    }

    public static function imageUrl($imagePath) {
        if (empty($imagePath)) {
            return self::placeholderUrl();
        }
        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            return $imagePath;
        }

        $relative = ltrim((string) $imagePath, '/');
        if (
            !str_starts_with($relative, self::UPLOAD_SUBDIR)
            && !str_starts_with($relative, 'uploads/')
        ) {
            $relative = self::UPLOAD_SUBDIR . $relative;
        }

        $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($localPath)) {
            return self::placeholderUrl();
        }

        return rtrim(BASE_URL, '/') . '/' . $relative;
    }

    public static function primaryImageUrl(array $product) {
        if (!empty($product['images'][0]['file_path'])) {
            return self::imageUrl($product['images'][0]['file_path']);
        }
        return self::imageUrl($product['image_path'] ?? '');
    }

    private function hasImagesTable() {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW TABLES LIKE 'product_images'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private function hasCategoryColumn() {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW COLUMNS FROM products LIKE 'category'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private function hasFraudStatusColumn() {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW COLUMNS FROM products LIKE 'image_fraud_status'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private function publicListingWhere(string $alias = 'p'): string {
        if (!$this->hasFraudStatusColumn()) {
            return '';
        }
        return " AND ({$alias}.image_fraud_status IS NULL OR {$alias}.image_fraud_status IN ('passed', 'skipped'))";
    }

    public function isPubliclyVisible(array $product): bool {
        $status = $product['image_fraud_status'] ?? 'skipped';
        return in_array($status, ['passed', 'skipped'], true);
    }

    public function updateFraudStatus(int $productId, string $status, ?float $score = null, ?array $reasons = null): bool {
        if (!$this->hasFraudStatusColumn()) {
            return false;
        }
        $reasonsJson = $reasons ? json_encode($reasons, JSON_UNESCAPED_UNICODE) : null;
        $sql = "UPDATE products SET image_fraud_status = ?, image_fraud_score = ?, image_fraud_reasons = ?, image_fraud_checked_at = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('sdsi', $status, $score, $reasonsJson, $productId);
        return $stmt->execute();
    }

    public function getFraudReviewQueue(int $limit = 100) {
        if (!$this->hasFraudStatusColumn()) {
            return false;
        }
        $sql = "SELECT p.*, u.username
                FROM products p
                JOIN users u ON u.username = p.seller_name
                WHERE p.image_fraud_status IN ('flagged', 'rejected', 'pending')
                ORDER BY p.image_fraud_checked_at DESC, p.id DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
}
