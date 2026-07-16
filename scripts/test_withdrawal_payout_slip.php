<?php
/**
 * Local smoke test: withdrawal request → admin approve with payout slip.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/WalletService.php';
require_once __DIR__ . '/../includes/order_helper.php';
require_once __DIR__ . '/../models/Wallet.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

$walletModel = new Wallet($conn);
$walletService = new WalletService($conn);
$walletModel->ensurePayoutSlipColumn();

$username = 'test1';
$userId = $walletModel->getUserIdByUsername($username);
assertTrue($userId !== null, "user {$username} exists");

// Ensure enough balance for test
$conn->query("UPDATE wallets SET available_balance = GREATEST(available_balance, 250) WHERE user_id = {$userId}");

// Ensure bank account
$banks = $walletModel->getBankAccounts($userId);
if (empty($banks)) {
    $bankId = $walletModel->saveBankAccount($userId, '004', 'ธนาคารกสิกรไทย', '1234567890', 'ทดสอบ ระบบ', true);
} else {
    $bankId = (int)$banks[0]['id'];
}
assertTrue($bankId > 0, "bank account ready #{$bankId}");

$before = $walletModel->getOrCreateWallet($userId);
$amount = 100.00;
$result = $walletService->requestWithdrawal($username, $amount, $bankId);
assertTrue(!empty($result['ok']), 'requestWithdrawal: ' . ($result['error'] ?? 'ok'));
$requestId = (int)$result['request_id'];
assertTrue($requestId > 0, "withdrawal request #{$requestId}");

$afterHold = $walletModel->getOrCreateWallet($userId);
assertTrue(
    abs(((float)$before['available_balance'] - $amount) - (float)$afterHold['available_balance']) < 0.001,
    'balance held on request'
);

// Create minimal valid PNG as payout slip (no GD required)
$slipDir = flixoUploadsSubdir('withdrawal_slips');
$slipName = 'test_payout_' . bin2hex(random_bytes(8)) . '.png';
// 1x1 PNG
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($slipDir . $slipName, $png);
assertTrue(is_file($slipDir . $slipName), 'slip file written');

$ok = $walletService->completeWithdrawal($requestId, 'admin', $slipName, 'ทดสอบโอน manual');
assertTrue($ok, 'completeWithdrawal with slip');

$req = $walletModel->findWithdrawalById($requestId);
assertTrue($req !== null && $req['status'] === 'completed', 'status completed');
assertTrue(($req['payout_slip'] ?? '') === $slipName, 'payout_slip stored');

$path = withdrawalSlipPath($slipName);
assertTrue($path !== null, 'withdrawalSlipPath resolves');

$url = withdrawalSlipUrl($requestId);
assertTrue(str_contains($url, 'WithdrawalSlipController.php') && str_contains($url, (string)$requestId), 'slip url built');

// Reject path smoke: create another request and reject
$conn->query("UPDATE wallets SET available_balance = GREATEST(available_balance, 150) WHERE user_id = {$userId}");
$result2 = $walletService->requestWithdrawal($username, 100.00, $bankId);
assertTrue(!empty($result2['ok']), 'second requestWithdrawal');
$rid2 = (int)$result2['request_id'];
$balBeforeReject = (float)$walletModel->getOrCreateWallet($userId)['available_balance'];
$rej = $walletService->rejectWithdrawal($rid2, 'admin', 'บัญชีไม่ตรง KYC (ทดสอบ)');
assertTrue($rej, 'rejectWithdrawal');
$balAfterReject = (float)$walletModel->getOrCreateWallet($userId)['available_balance'];
assertTrue(abs(($balBeforeReject + 100) - $balAfterReject) < 0.001, 'balance restored on reject');

$history = $walletModel->getUserWithdrawals($userId, 5);
assertTrue(count($history) >= 2, 'user withdrawal history listed');

echo "\nALL WITHDRAWAL SMOKE TESTS PASSED\n";
