<?php
// controllers/PrivacyController.php — PDPA self-service

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../models/Consent.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';

if (!isset($_SESSION['user'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? '');

$action = $_POST['action'] ?? '';
$me = $_SESSION['user'];
$userModel = new User($conn);
$user = $userModel->findByUsername($me);
if (!$user) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}
$userId = (int) $user['id'];
$consentModel = new Consent($conn);

try {
    switch ($action) {
        case 'export_data':
            exportUserData($conn, $user, $me);
            break;

        case 'withdraw_consent':
            header('Content-Type: application/json; charset=utf-8');
            $type = $_POST['consent_type'] ?? '';
            $reason = trim($_POST['reason'] ?? '');
            if (!in_array($type, ['privacy_policy', 'kyc_data_collection', 'marketing'], true)) {
                throw new Exception('ประเภท consent ไม่ถูกต้อง');
            }
            if (!$consentModel->withdraw($userId, $type, $reason)) {
                throw new Exception('ไม่สามารถถอนความยินยอมได้');
            }
            echo json_encode(['status' => 'success', 'message' => 'ถอนความยินยอมเรียบร้อย']);
            break;

        case 'request_deletion':
            header('Content-Type: application/json; charset=utf-8');
            $note = trim($_POST['note'] ?? '');
            $stmt = $conn->prepare(
                "INSERT INTO data_subject_requests (user_id, request_type, status, admin_note) VALUES (?, 'delete', 'pending', ?)"
            );
            $stmt->bind_param('is', $userId, $note);
            if (!$stmt->execute()) {
                throw new Exception('ไม่สามารถส่งคำขอลบบัญชีได้');
            }
            echo json_encode(['status' => 'success', 'message' => 'ส่งคำขอลบบัญชีแล้ว ทีมงานจะดำเนินการภายใน 30 วัน']);
            break;

        case 'reconsent':
            header('Content-Type: application/json; charset=utf-8');
            if (empty($_POST['consent_terms']) || empty($_POST['consent_privacy'])) {
                throw new Exception('กรุณายอมรับข้อกำหนดและนโยบายความเป็นส่วนตัว');
            }
            $consentModel->record($userId, 'terms_of_service', TERMS_VERSION);
            $consentModel->record($userId, 'privacy_policy', PRIVACY_VERSION);
            echo json_encode(['status' => 'success', 'message' => 'บันทึกความยินยอมแล้ว', 'redirect' => 'dashboard.php']);
            break;

        default:
            header('Content-Type: application/json; charset=utf-8');
            throw new Exception('Action ไม่ถูกต้อง');
    }
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

function exportUserData(mysqli $conn, array $user, string $username): void {
    $consentModel = new Consent($conn);

    $orders = [];
    $stmt = $conn->prepare(
        "SELECT id, item_name, amount, status, created_at
         FROM orders
         WHERE buyer_name = ? OR seller_name = ?
         ORDER BY id DESC
         LIMIT 500"
    );
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $orders[] = $row;
    }

    $export = [
        'exported_at' => date('c'),
        'profile' => [
            'username' => $user['username'],
            'email' => $user['email'],
            'real_name' => $user['real_name'] ?? '',
            'phone' => $user['phone'] ?? '',
            'created_at' => $user['created_at'] ?? '',
            'kyc_status' => $user['kyc_status'] ?? '',
            'is_verified' => !empty($user['is_verified']),
        ],
        'consents' => $consentModel->getByUser((int) $user['id']),
        'orders' => $orders,
    ];

    $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new Exception('ไม่สามารถสร้างไฟล์ข้อมูลได้');
    }

    $filename = 'flixo_export_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $username) . '_' . date('Ymd_His') . '.json';
    $savedPath = '';

    // Prefer persistent uploads volume (Railway); fall back to storage/; skip if neither writable.
    foreach ([flixoUploadsSubdir('exports'), ROOT_PATH . '/storage/exports/'] as $dir) {
        $dir = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            continue;
        }
        if (!is_writable($dir)) {
            continue;
        }
        $path = $dir . $filename;
        if (@file_put_contents($path, $json) !== false) {
            $savedPath = $path;
            break;
        }
    }

    $userId = (int) $user['id'];
    $logPath = $savedPath !== '' ? $savedPath : ('inline:' . $filename);
    $log = $conn->prepare(
        "INSERT INTO data_subject_requests (user_id, request_type, status, payload_path, completed_at)
         VALUES (?, 'export', 'completed', ?, NOW())"
    );
    if ($log) {
        $log->bind_param('is', $userId, $logPath);
        $log->execute();
    }

    // Force download path in the browser (do not use only application/json).
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($json));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}
