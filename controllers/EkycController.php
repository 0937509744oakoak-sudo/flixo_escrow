<?php
// controllers/EkycController.php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/EkycService.php';
require_once __DIR__ . '/../models/User.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user'])) {
        throw new Exception('กรุณาเข้าสู่ระบบ', 403);
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $me = $_SESSION['user'];
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $userModel = new User($conn);
    if ($userId <= 0) {
        $row = $userModel->findByUsername($me);
        $userId = (int)($row['id'] ?? 0);
    }

    $ekyc = new EkycService($conn);

    if ($action === 'sumsub_token') {
        if (EkycService::activeProvider() !== EkycService::PROVIDER_SUMSUB) {
            throw new Exception('Sumsub eKYC is not active');
        }
        $token = $ekyc->getSumsubSdkToken($userId, $me);
        echo json_encode(['status' => 'success', 'data' => $token], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync_status') {
        verifyCsrfToken($_POST['csrf_token'] ?? '');
        if (EkycService::activeProvider() !== EkycService::PROVIDER_SUMSUB) {
            throw new Exception('Sumsub eKYC is not active');
        }
        $result = $ekyc->syncReviewFromSumsub($userId);
        echo json_encode(['status' => 'success', 'data' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'provider_info') {
        echo json_encode([
            'status' => 'success',
            'provider' => EkycService::activeProvider(),
            'sumsub_configured' => EkycService::isSumsubConfigured(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Invalid action');
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
