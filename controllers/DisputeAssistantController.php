<?php
// controllers/DisputeAssistantController.php — AI dispute analysis API (admin only)
require_once __DIR__ . '/../includes/init.php';
require __DIR__ . '/admin_check_proxy.php';
require_once __DIR__ . '/../includes/Rbac.php';
require_once __DIR__ . '/../includes/DisputeAssistantService.php';
require_once __DIR__ . '/../models/Dispute.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Rbac::requirePermission($conn, $_SESSION['user'], 'dispute.resolve');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $disputeId = (int)($_GET['dispute_id'] ?? $_POST['dispute_id'] ?? 0);

    if ($disputeId <= 0) {
        throw new Exception('Invalid dispute ID');
    }

    $disputeModel = new Dispute($conn);
    $dispute = $disputeModel->findById($disputeId);
    if (!$dispute) {
        throw new Exception('ไม่พบข้อพิพาท');
    }

    $service = new DisputeAssistantService($conn);
    $adminUser = $_SESSION['user'];

    if ($action === 'get_latest') {
        $latest = $service->getLatestFormatted($disputeId);
        echo json_encode(['status' => 'success', 'analysis' => $latest], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'analyze') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken($_POST['csrf_token'] ?? '');
        }
        $force = isset($_POST['force']) && $_POST['force'] === '1';
        $result = $service->analyze($disputeId, $adminUser, $force);
        $ok = ($result['status'] ?? '') !== 'error';
        echo json_encode([
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'วิเคราะห์เสร็จแล้ว' : ($result['error_message'] ?? 'วิเคราะห์ไม่สำเร็จ'),
            'analysis' => $result,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Action ไม่ถูกต้อง');

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
