<?php
// api/webhook_ekyc.php — Sumsub applicantReviewed webhook

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/EkycService.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
if ($raw === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'empty body']));
}

// This endpoint can grant KYC-verified status, which is the gate for
// withdrawals — so an unverifiable request must be rejected, never trusted.
$webhookSecret = defined('SUMSUB_WEBHOOK_SECRET') ? SUMSUB_WEBHOOK_SECRET : '';
if ($webhookSecret === '') {
    ErrorLogger::write('error', 'eKYC webhook rejected: SUMSUB_WEBHOOK_SECRET is not configured');
    http_response_code(503);
    exit(json_encode(['error' => 'webhook not configured']));
}

$digest = $_SERVER['HTTP_X_PAYLOAD_DIGEST'] ?? '';
$expected = hash_hmac('sha256', $raw, $webhookSecret);
if (!is_string($digest) || !hash_equals($expected, $digest)) {
    http_response_code(401);
    exit(json_encode(['error' => 'invalid signature']));
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit(json_encode(['error' => 'invalid json']));
}

$type = $payload['type'] ?? '';
$data = $payload['applicant'] ?? $payload['reviewResult'] ?? $payload;

try {
    $ekyc = new EkycService($conn);

    if ($type === 'applicantReviewed' || isset($payload['reviewResult'])) {
        $review = $payload['reviewResult'] ?? [];
        $answer = $review['reviewAnswer'] ?? ($review['reviewanswer'] ?? '');
        $externalUserId = $payload['externalUserId']
            ?? ($payload['applicant']['externalUserId'] ?? ($data['externalUserId'] ?? ''));
        $userId = $ekyc->findUserIdByExternalRef($externalUserId);

        if ($userId && $answer !== '') {
            $labels = isset($review['rejectLabels']) ? json_encode($review['rejectLabels']) : null;
            $ekyc->applyReviewResult($userId, $answer, $labels, $payload);

            $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $u = $stmt->get_result()->fetch_assoc();
            if ($u) {
                require_once __DIR__ . '/../includes/order_helper.php';
                if (strtoupper($answer) === 'GREEN') {
                    createNotification(
                        $conn,
                        $u['username'],
                        "✅ ยืนยันตัวตน (eKYC) สำเร็จแล้ว!\n\nคุณสามารถถอนเงินและใช้งานฟีเจอร์ครบได้แล้ว หากมีคำถาม พิมพ์ถามในแชทนี้ได้เลย",
                        'dashboard.php?tab=profile'
                    );
                } elseif (strtoupper($answer) === 'RED') {
                    createNotification(
                        $conn,
                        $u['username'],
                        "❌ การยืนยันตัวตน (eKYC) ยังไม่ผ่านในรอบนี้\n\nกรุณาตรวจสอบเอกสารแล้วลองส่งใหม่อีกครั้ง หากไม่แน่ใจว่าติดปัญหาตรงไหน พิมพ์ถามเราในแชทนี้ได้เลย",
                        'kyc_verify.php'
                    );
                }
            }
        }
    }

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('eKYC webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'processing failed']);
}
