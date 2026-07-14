<?php
/**
 * Test Sumsub sandbox credentials (no secrets printed).
 * Usage: php scripts/test_sumsub_config.php
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EkycService.php';

echo "Sumsub configuration test\n";
echo "=========================\n\n";

$configured = EkycService::isSumsubConfigured();
echo 'Configured: ' . ($configured ? 'YES' : 'NO') . "\n";
echo 'Provider: ' . EkycService::activeProvider() . "\n";
echo 'Level: ' . (defined('SUMSUB_LEVEL_NAME') ? SUMSUB_LEVEL_NAME : '-') . "\n";
echo 'Token prefix: ' . (defined('SUMSUB_APP_TOKEN') && SUMSUB_APP_TOKEN !== '' ? substr(SUMSUB_APP_TOKEN, 0, 4) . '...' : 'missing') . "\n\n";

if (!$configured) {
    fwrite(STDERR, "SUMSUB_APP_TOKEN or SUMSUB_SECRET_KEY missing in .env\n");
    exit(1);
}

// Ping Sumsub: list levels (GET /resources/applicants/-; level check via access token dry run)
$ts = (string) time();
$path = '/resources/accessTokens/sdk';
$body = json_encode(['userId' => 'flixo_test_ping', 'levelName' => SUMSUB_LEVEL_NAME, 'ttlInSecs' => 600]);
$sig = hash_hmac('sha256', $ts . 'POST' . $path . $body, SUMSUB_SECRET_KEY);

$ch = curl_init('https://api.sumsub.com' . $path);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-App-Token: ' . SUMSUB_APP_TOKEN,
        'X-App-Access-Ts: ' . $ts,
        'X-App-Access-Sig: ' . $sig,
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "Connection error: {$err}\n";
    exit(1);
}

$data = json_decode($resp ?: '{}', true);
if ($code >= 200 && $code < 300 && !empty($data['token'])) {
    echo "API connection: OK (sandbox SDK token received)\n";
    echo "Next: open http://localhost/flixo_escrow/kyc_verify.php while logged in\n";
    exit(0);
}

$msg = $data['description'] ?? $data['errorName'] ?? $data['error'] ?? substr($resp, 0, 200);
echo "API connection: FAILED (HTTP {$code})\n";
echo "Message: {$msg}\n";
if ($code === 404 || stripos((string)$msg, 'level') !== false) {
    echo "\nTip: Check SUMSUB_LEVEL_NAME in Sumsub Dashboard → Verification levels.\n";
    echo "Common sandbox names: basic-kyc-level, id-and-liveness, id-only\n";
}
exit(1);
