<?php
// controllers/WalletController.php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/WalletService.php';
require_once __DIR__ . '/../models/Wallet.php';
verifyCsrfToken($_POST['csrf_token'] ?? '');

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_SESSION['user'])) {
        throw new Exception('กรุณาเข้าสู่ระบบ', 403);
    }

    $me = $_SESSION['user'];
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        $w = new Wallet($conn);
        $userId = (int)($w->getUserIdByUsername($me) ?? 0);
    }

    $action = $_POST['action'] ?? '';
    $walletModel = new Wallet($conn);
    $walletService = new WalletService($conn);

    if ($action === 'save_bank') {
        $bankCode = trim($_POST['bank_code'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = preg_replace('/[^0-9]/', '', $_POST['account_number'] ?? '');
        $accountName = trim($_POST['account_name'] ?? '');

        if ($bankCode === '' || $bankName === '' || strlen($accountNumber) < 10 || $accountName === '') {
            throw new Exception('กรุณากรอกข้อมูลบัญชีธนาคารให้ครบถ้วน');
        }

        $stmt = $conn->prepare('SELECT real_name, is_verified FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $kycName = trim((string)($userRow['real_name'] ?? ''));
        if (!empty($userRow['is_verified'])) {
            if ($kycName === '') {
                throw new Exception('ไม่พบชื่อจากการยืนยันตัวตน — ติดต่อฝ่ายสนับสนุน');
            }
            if (!bankAccountNameMatchesKyc($accountName, $kycName)) {
                throw new Exception('ชื่อบัญชีต้องตรงกับชื่อที่ยืนยันตัวตน (KYC): ' . $kycName);
            }
        }

        $id = $walletModel->saveBankAccount($userId, $bankCode, $bankName, $accountNumber, $accountName, true);
        echo json_encode(['status' => 'success', 'bank_account_id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'withdraw') {
        $amount = (float)($_POST['amount'] ?? 0);
        $bankAccountId = (int)($_POST['bank_account_id'] ?? 0);

        $stmt = $conn->prepare("SELECT is_verified FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if (empty($u['is_verified'])) {
            throw new Exception('กรุณายืนยันตัวตน (KYC) ก่อนถอนเงิน');
        }

        $result = $walletService->requestWithdrawal($me, $amount, $bankAccountId);
        if (!$result['ok']) {
            throw new Exception($result['error'] ?? 'ไม่สามารถสร้างคำขอถอนได้');
        }
        echo json_encode(['status' => 'success', 'request_id' => $result['request_id'], 'message' => 'ส่งคำขอถอนเงินแล้ว รอแอดมินอนุมัติ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Invalid action');
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
