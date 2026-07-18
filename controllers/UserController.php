<?php
// controllers/UserController.php
require_once __DIR__ . '/../includes/init.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../includes/legal.php';
require_once __DIR__ . '/../includes/PiiEncryption.php';
require_once __DIR__ . '/../includes/EkycService.php';
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

        $kycDir = __DIR__ . '/../uploads/kyc/';
        $id_name = secureUpload($_FILES['id_card'], $kycDir);
        $id_path = realpath($kycDir . $id_name);

        $selfie_name = secureUpload($_FILES['selfie'], $kycDir);
        $selfie_path = realpath($kycDir . $selfie_name);

        $useAigen = EkycService::activeProvider() === EkycService::PROVIDER_AIGEN
            && $userId > 0
            && $id_path
            && $selfie_path;

        $aigenResult = null;
        if ($useAigen) {
            $ekyc = new EkycService($conn);
            try {
                $aigenResult = $ekyc->verifyWithAigen($userId, $id_path, $selfie_path);
            } catch (Throwable $e) {
                error_log('AiGen KYC: ' . $e->getMessage());
                // API down / credit — keep docs for admin review
                $ekyc->markAigenPending($userId, ['error' => $e->getMessage()]);
                $aigenResult = [
                    'status' => 'pending',
                    'verified' => false,
                    'message' => 'ระบบตรวจอัตโนมัติไม่พร้อม — ส่งเอกสารแล้ว รอแอดมินตรวจสอบ',
                ];
            }
        }

        if ($id_path) {
            $id_name = PiiEncryption::encryptFile($id_path);
        }
        if ($selfie_path) {
            $selfie_name = PiiEncryption::encryptFile($selfie_path);
        }

        if ($aigenResult !== null && ($aigenResult['status'] ?? '') === 'approved') {
            // Images + approval already applied by verifyWithAigen; still store filenames
            $stmt = $conn->prepare("UPDATE users SET id_card_image = ?, selfie_image = ?, ekyc_provider = 'aigen' WHERE username = ?");
            $stmt->bind_param('sss', $id_name, $selfie_name, $me);
            if (!$stmt->execute()) {
                throw new Exception('Database Update Failed');
            }
            $consentModel = new Consent($conn);
            $consentModel->record($userId, 'kyc_data_collection', KYC_CONSENT_VERSION);
            require_once __DIR__ . '/../includes/support_chat.php';
            sendSupportMessage(
                $conn,
                $me,
                "✅ ยืนยันตัวตน (eKYC) สำเร็จแล้ว!\n\nคุณสามารถถอนเงินและใช้งานฟีเจอร์ครบได้แล้ว หากมีคำถาม พิมพ์ถามในแชทนี้ได้เลย"
            );
            echo json_encode([
                'status' => 'success',
                'kyc_status' => 'approved',
                'verified' => true,
                'real_name' => $aigenResult['real_name'] ?? null,
                'message' => $aigenResult['message'] ?? 'ยืนยันตัวตนสำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($aigenResult !== null && ($aigenResult['status'] ?? '') === 'rejected') {
            $stmt = $conn->prepare("UPDATE users SET id_card_image = ?, selfie_image = ?, ekyc_provider = 'aigen' WHERE username = ?");
            $stmt->bind_param('sss', $id_name, $selfie_name, $me);
            $stmt->execute();
            if ($userId > 0) {
                $consentModel = new Consent($conn);
                $consentModel->record($userId, 'kyc_data_collection', KYC_CONSENT_VERSION);
            }
            echo json_encode([
                'status' => 'error',
                'kyc_status' => 'rejected',
                'verified' => false,
                'message' => $aigenResult['message'] ?? 'ยืนยันตัวตนไม่ผ่าน',
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($userModel->updateKyc($me, $id_name, $selfie_name)) {
            if ($userId > 0) {
                $consentModel = new Consent($conn);
                $consentModel->record($userId, 'kyc_data_collection', KYC_CONSENT_VERSION);
                if ($aigenResult !== null && ($aigenResult['status'] ?? '') === 'pending') {
                    $stmt = $conn->prepare("UPDATE users SET ekyc_provider = 'aigen', kyc_status = 'pending' WHERE id = ?");
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                }
            }
            echo json_encode([
                'status' => 'success',
                'kyc_status' => 'pending',
                'verified' => false,
                'message' => $aigenResult['message'] ?? 'ส่งเอกสารแล้ว รอตรวจสอบ',
            ], JSON_UNESCAPED_UNICODE);
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