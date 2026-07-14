<?php
// controllers/UserController.php
require_once __DIR__ . '/../includes/init.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/PiiEncryption.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Consent.php';
verifyCsrfToken($_POST['csrf_token'] ?? '');

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user'])) {
        throw new Exception('กรุณาเข้าสู่ระบบ', 403);
    }

    $action = $_POST['action'] ?? '';
    $me = $_SESSION['user'];
    
    // เรียกใช้ Model
    $userModel = new User($conn);

    if ($action == 'submit_kyc') {
        if (empty($_POST['consent_kyc'])) {
            throw new Exception('กรุณายินยอมให้จัดเก็บและประมวลผลข้อมูล KYC');
        }
        if (!isset($_FILES['id_card'])) {
            throw new Exception('ไม่พบไฟล์บัตรประชาชน');
        }
        if (!isset($_FILES['selfie'])) {
            throw new Exception('กรุณาถ่ายรูปใบหน้า (Selfie) เพื่อยืนยันตัวตน');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $userRow = $userModel->findByUsername($me);
            $userId = (int)($userRow['id'] ?? 0);
        }

        $id_name = secureUpload($_FILES['id_card'], __DIR__ . '/../uploads/kyc/');
        $id_path = realpath(__DIR__ . '/../uploads/kyc/' . $id_name);
        if ($id_path) {
            $id_name = PiiEncryption::encryptFile($id_path);
        }

        $selfie_name = secureUpload($_FILES['selfie'], __DIR__ . '/../uploads/kyc/');
        $selfie_path = realpath(__DIR__ . '/../uploads/kyc/' . $selfie_name);
        if ($selfie_path) {
            $selfie_name = PiiEncryption::encryptFile($selfie_path);
        }

        if ($userModel->updateKyc($me, $id_name, $selfie_name)) {
            if ($userId > 0) {
                $consentModel = new Consent($conn);
                $consentModel->record($userId, 'kyc_data_collection', KYC_CONSENT_VERSION);
            }
            echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Database Update Failed');
        }
    } elseif ($action === 'update_display_name') {
        $realName = trim($_POST['real_name'] ?? '');
        if ($realName === '') {
            throw new Exception('กรุณาระบุชื่อ-นามสกุล');
        }
        if (mb_strlen($realName) > 100) {
            throw new Exception('ชื่อ-นามสกุลยาวเกินไป');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            $userRow = $userModel->findByUsername($me);
            $userId = (int) ($userRow['id'] ?? 0);
        }
        if ($userId <= 0) {
            throw new Exception('ไม่พบบัญชีผู้ใช้');
        }

        if (!$userModel->updateRealName($userId, $realName)) {
            throw new Exception('ไม่สามารถบันทึกชื่อได้');
        }

        echo json_encode([
            'status' => 'success',
            'display_name' => $realName,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('คำสั่งไม่ถูกต้อง');
    }

} catch (Exception $e) {
    error_log("Controller Error [UserController]: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>