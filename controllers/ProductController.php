<?php
// controllers/ProductController.php
require_once __DIR__ . '/../includes/init.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../includes/ProductImageFraudService.php';
verifyCsrfToken($_POST['csrf_token'] ?? '');

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user'])) {
        throw new Exception('กรุณาเข้าสู่ระบบก่อนทำรายการ', 403);
    }

    $action = $_POST['action'] ?? '';
    $me = $_SESSION['user'];
    $productModel = new Product($conn);

    if ($action === 'sell_product') {
        if (defined('EKYC_REQUIRED_FOR_SELL') && EKYC_REQUIRED_FOR_SELL) {
            $userModel = new User($conn);
            if (!$userModel->isVerified($me)) {
                throw new Exception('กรุณายืนยันตัวตน (eKYC) ก่อนลงประกาศขาย');
            }
        }
        $p_name = trim(filter_var($_POST['product_name'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
        $category = trim(filter_var($_POST['product_category'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
        $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
        $desc = trim(filter_var($_POST['description'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));

        if ($price === false || $price <= 0 || $p_name === '') {
            throw new Exception('กรุณากรอกชื่อสินค้าและราคาให้ถูกต้อง');
        }
        if (empty($_POST['consent_listing_rules'])) {
            throw new Exception('กรุณายืนยันว่าสินค้าไม่ผิดกฎหมาย ไม่ละเมิดลิขสิทธิ์ และไม่ใช่สินค้าควบคุม');
        }
        if (mb_strlen($p_name) > 100) {
            $p_name = mb_substr($p_name, 0, 100);
        }
        if (mb_strlen($category) > 64) {
            $category = mb_substr($category, 0, 64);
        }
        if (mb_strlen($desc) > 1000) {
            $desc = mb_substr($desc, 0, 1000);
        }

        $uploadFiles = normalizeFileUploads('product_images');
        if (empty($uploadFiles)) {
            $legacy = normalizeFileUploads('product_image');
            $uploadFiles = $legacy;
        }

        if (empty($uploadFiles)) {
            throw new Exception('กรุณาอัปโหลดรูปภาพสินค้าอย่างน้อย 1 รูป');
        }
        if (count($uploadFiles) > Product::MAX_IMAGES) {
            throw new Exception('อัปโหลดได้สูงสุด ' . Product::MAX_IMAGES . ' รูป');
        }

        $upload_dir = flixoUploadsSubdir('products');
        $savedImages = [];

        try {
            $savedImages = secureUploadMany($uploadFiles, $upload_dir);

            $fraudService = new ProductImageFraudService($conn);
            $absolutePaths = array_map(static function ($filename) use ($upload_dir) {
                return $upload_dir . $filename;
            }, $savedImages);
            $fraudResult = $fraudService->scanImages($absolutePaths, [
                'product_name' => $p_name,
                'category' => $category,
                'description' => $desc,
            ]);

            if (
                $fraudResult['verdict'] === ProductImageFraudService::VERDICT_REJECT
                && defined('IMAGE_FRAUD_BLOCK')
                && IMAGE_FRAUD_BLOCK
            ) {
                foreach ($savedImages as $filename) {
                    deleteProductImageFile($filename);
                }
                throw new Exception($fraudService->formatUserMessage($fraudResult));
            }

            $productId = $productModel->createProduct($me, $p_name, $category, $price, $desc, $savedImages, $fraudResult);
            $fraudService->logScans($productId, $fraudResult['per_image'] ?? []);

            // ประกาศแสดงต่อสาธารณะทันที → แจ้ง feed ผู้ติดตามร้าน
            if (in_array($fraudResult['status'] ?? 'skipped', ['passed', 'skipped'], true)) {
                try {
                    require_once __DIR__ . '/../includes/FollowerFeedService.php';
                    FollowerFeedService::notifyNewListing($conn, $me, $productId, $p_name, (float) $price);
                } catch (Throwable $feedError) {
                    error_log('ProductController: follower feed failed — ' . $feedError->getMessage());
                }
            }

            $response = [
                'status' => 'success',
                'product_id' => $productId,
                'image_count' => count($savedImages),
            ];
            if (($fraudResult['status'] ?? '') === 'flagged') {
                $response['flagged'] = true;
                $response['message'] = 'ลงประกาศแล้ว แต่รูปภาพถูกส่งตรวจสอบเพิ่มเติมก่อนแสดงต่อสาธารณะ';
            }
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            foreach ($savedImages as $filename) {
                deleteProductImageFile($filename);
            }
            throw $e;
        }
    } elseif ($action === 'delete_product') {
        $p_id = filter_var($_POST['product_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$p_id) {
            throw new Exception('ไม่พบรหัสสินค้า');
        }

        if ($productModel->delete($p_id, $me)) {
            echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('ไม่สามารถลบสินค้าได้');
        }
    } else {
        throw new Exception('คำสั่งไม่ถูกต้อง');
    }
} catch (Exception $e) {
    error_log('Controller Error [ProductController]: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
