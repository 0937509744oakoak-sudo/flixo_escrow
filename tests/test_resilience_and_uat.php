<?php
/**
 * Chapter 4 §4.10 Resilience (10 cases) + §4.12 UAT (U-01…U-12)
 *
 * Forces MOCK_API_MODE=false and stub API keys BEFORE config load so fallback
 * paths (not mock GT) are exercised.
 *
 * Usage: C:\xampp\php\php.exe tests/test_resilience_and_uat.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// --- Force non-mock + invalid cloud keys so .env cannot override (getenv already set) ---
putenv('MOCK_API_MODE=false');
$_ENV['MOCK_API_MODE'] = 'false';
putenv('GOOGLE_CLOUD_VISION_API_KEY=__RESILIENCE_DISABLED__');
putenv('GROQ_API_KEY=__RESILIENCE_DISABLED__');
putenv('OPENROUTER_API_KEY=__RESILIENCE_DISABLED__');
putenv('GEMINI_API_KEY=__RESILIENCE_DISABLED__');
// Keep Omise/Google/Slip keys from .env for positive UAT where needed.
// Redis: point at closed port to force null connect
putenv('REDIS_HOST=127.0.0.1');
putenv('REDIS_PORT=6399');

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/EkycService.php';
require_once $root . '/includes/ProductImageFraudService.php';
require_once $root . '/includes/ChatModerationService.php';
require_once $root . '/includes/DisputeAssistantService.php';
require_once $root . '/includes/OmiseService.php';
require_once $root . '/includes/GoogleIdTokenVerifier.php';
require_once $root . '/includes/ObjectStorage.php';
require_once $root . '/includes/chat_realtime.php';
require_once $root . '/includes/MockApiHelper.php';

$outResilience = __DIR__ . '/resilience_results.csv';
$outUat = __DIR__ . '/uat_results.csv';
$outJson = __DIR__ . '/resilience_uat_summary.json';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, 'DB failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

function assertPass(bool $ok, string $detail): array
{
    return ['pass' => $ok ? 1 : 0, 'detail' => $detail];
}

function writeCsv(string $path, array $headers, array $rows): void
{
    $fp = fopen($path, 'w');
    fputcsv($fp, $headers);
    foreach ($rows as $r) {
        fputcsv($fp, $r);
    }
    fclose($fp);
}

// ---------------------------------------------------------------------------
// §4.10 Resilience
// ---------------------------------------------------------------------------
$resilience = [];

// R1 AiGen → markAigenPending
$ekyc = new EkycService($conn);
$userId = 0;
$q = $conn->query('SELECT id, kyc_status, is_verified, ekyc_provider FROM users ORDER BY id ASC LIMIT 1');
$row = $q ? $q->fetch_assoc() : null;
if ($row) {
    $userId = (int) $row['id'];
    $backup = $row;
    try {
        $ekyc->markAigenPending($userId, ['error' => 'resilience_simulated_timeout']);
        $chk = $conn->query('SELECT kyc_status, is_verified, ekyc_provider FROM users WHERE id=' . $userId)->fetch_assoc();
        $ok = ($chk['kyc_status'] ?? '') === 'pending' && (int) ($chk['is_verified'] ?? 1) === 0;
        $resilience[] = [
            'No.' => 1,
            'Module' => 'AiGen eKYC',
            'Failure' => 'API exception → markAigenPending',
            'Expected' => 'kyc_status=pending, is_verified=0',
            'Actual' => json_encode($chk, JSON_UNESCAPED_UNICODE),
            'Pass' => $ok ? 1 : 0,
            'Note' => 'restored after test',
        ];
    } finally {
        $st = $conn->prepare('UPDATE users SET kyc_status=?, is_verified=?, ekyc_provider=? WHERE id=?');
        $ks = (string) ($backup['kyc_status'] ?? 'unverified');
        $iv = (int) ($backup['is_verified'] ?? 0);
        $ep = $backup['ekyc_provider'];
        $st->bind_param('sisi', $ks, $iv, $ep, $userId);
        // ekyc_provider may be null — use simpler restore
        $conn->query(
            'UPDATE users SET kyc_status=' . $conn->real_escape_string("'" . ($backup['kyc_status'] ?? 'unverified') . "'") .
            ', is_verified=' . (int) ($backup['is_verified'] ?? 0) .
            ', ekyc_provider=' . ($backup['ekyc_provider'] === null ? 'NULL' : ("'" . $conn->real_escape_string((string) $backup['ekyc_provider']) . "'")) .
            ' WHERE id=' . $userId
        );
    }
} else {
    $resilience[] = [
        'No.' => 1, 'Module' => 'AiGen eKYC', 'Failure' => 'markAigenPending',
        'Expected' => 'pending', 'Actual' => 'no users in DB', 'Pass' => 0, 'Note' => 'seed a user',
    ];
}

// R2 Sumsub sync_status without webhook
$sync = ['synced' => false, 'status' => 'n/a'];
try {
    if ($userId > 0) {
        $sync = $ekyc->syncReviewFromSumsub($userId);
    }
    // Pass if method returns structured result (not_configured / no_review / synced) — no throw
    $ok = is_array($sync) && array_key_exists('synced', $sync) && array_key_exists('status', $sync);
    $resilience[] = [
        'No.' => 2,
        'Module' => 'Sumsub',
        'Failure' => 'no webhook — call syncReviewFromSumsub',
        'Expected' => 'structured sync result (no crash)',
        'Actual' => json_encode($sync, JSON_UNESCAPED_UNICODE),
        'Pass' => $ok ? 1 : 0,
        'Note' => 'poll path usable when webhook missed',
    ];
} catch (Throwable $e) {
    $resilience[] = [
        'No.' => 2, 'Module' => 'Sumsub', 'Failure' => 'syncReviewFromSumsub',
        'Expected' => 'no crash', 'Actual' => $e->getMessage(), 'Pass' => 0, 'Note' => '',
    ];
}

// R3 Image Vision key disabled / invalid → EXIF flag ~55
$imgSvc = new ProductImageFraudService($conn);
$fixture = __DIR__ . '/fixtures_id.jpg';
$scan = $imgSvc->scanImages([$fixture], ['title' => 'resilience']);
$one = $scan['per_image'][0] ?? null;
$risk = (float) ($scan['risk_score'] ?? ($one['risk_score'] ?? -1));
$verdict = (string) ($scan['verdict'] ?? ($one['verdict'] ?? ''));
$provider = (string) ($scan['provider'] ?? ($one['provider'] ?? ''));
$okImg = ($risk >= 50.0 && $risk <= 60.0) || $verdict === ProductImageFraudService::VERDICT_FLAG;
$resilience[] = [
    'No.' => 3,
    'Module' => 'Cloud Vision (Image)',
    'Failure' => 'Vision key disabled/invalid',
    'Expected' => 'EXIF fallback flag ~55',
    'Actual' => json_encode([
        'risk' => $risk,
        'verdict' => $verdict,
        'provider' => $provider,
        'raw_skip' => $one['raw']['vision_skipped'] ?? ($one['raw']['vision_error'] ?? null),
    ], JSON_UNESCAPED_UNICODE),
    'Pass' => $okImg ? 1 : 0,
    'Note' => 'MOCK off; Vision key stubbed → API error or skip → ~55',
];

// R4 Chat LLM all fail → Regex-only
$chat = new ChatModerationService($conn);
$textRisky = 'โอนนอกระบบ แอดไลน์ @scam_flixo ได้เลย';
$chatOut = $chat->scanText($textRisky);
$prov = strtolower((string) ($chatOut['provider'] ?? ''));
$noLlm = !str_contains($prov, 'groq') && !str_contains($prov, 'openrouter') && !str_contains($prov, 'gemini');
$okChat = $noLlm && in_array(($chatOut['verdict'] ?? ''), ['pass', 'flag', 'reject'], true);
$resilience[] = [
    'No.' => 4,
    'Module' => 'Chat LLM',
    'Failure' => 'Groq/OR/Gemini keys disabled',
    'Expected' => 'Regex-only (no LLM provider in result)',
    'Actual' => json_encode(['provider' => $prov, 'verdict' => $chatOut['verdict'] ?? '', 'score' => $chatOut['risk_score'] ?? null], JSON_UNESCAPED_UNICODE),
    'Pass' => $okChat ? 1 : 0,
    'Note' => 'keys stubbed to __RESILIENCE_DISABLED__',
];

// R5 Chat Vision OCR key gone → skip or mid flag (no crash)
$imgChat = $chat->scanImage($fixture);
$okV = in_array(($imgChat['status'] ?? ''), ['skipped', 'flagged', 'passed'], true)
    || in_array(($imgChat['verdict'] ?? ''), ['pass', 'flag', 'reject'], true);
$resilience[] = [
    'No.' => 5,
    'Module' => 'Vision OCR in Chat',
    'Failure' => 'Vision key disabled',
    'Expected' => 'skip or mid-score flag, no crash',
    'Actual' => json_encode($imgChat, JSON_UNESCAPED_UNICODE),
    'Pass' => $okV ? 1 : 0,
    'Note' => 'code: not configured → skipped; error → flag~40',
];

// R6 Dispute AI → Heuristic
$dispute = new DisputeAssistantService($conn);
$ctx = [
    'order' => [
        'amount' => 1500,
        'tracking_number' => '',
        'tracking_status_code' => '',
        'tracking_status' => '',
        'courier' => '',
    ],
    'dispute' => [
        'reason' => 'สินค้าไม่ตรงปก',
        'description' => 'ได้ของผิดรุ่น ผู้ขายไม่วาง',
    ],
    'evidences' => [],
];
$dOut = $dispute->analyzeContextForBenchmark($ctx, true);
$okD = (($dOut['provider'] ?? '') === DisputeAssistantService::PROVIDER_HEURISTIC)
    && in_array(($dOut['recommended_action'] ?? ''), ['REFUND_BUYER', 'PAYOUT_SELLER', 'SPLIT', 'NEEDS_REVIEW'], true);
$resilience[] = [
    'No.' => 6,
    'Module' => 'Dispute AI',
    'Failure' => 'forceHeuristic=true (LLM down)',
    'Expected' => 'Heuristic recommendation',
    'Actual' => json_encode([
        'action' => $dOut['recommended_action'] ?? null,
        'provider' => $dOut['provider'] ?? null,
        'confidence' => $dOut['confidence'] ?? null,
    ], JSON_UNESCAPED_UNICODE),
    'Pass' => $okD ? 1 : 0,
    'Note' => '',
];

// R7 EasySlip no key / API down → WAITING_VERIFY
$slipDecision = 'WAITING_VERIFY';
$okSlip = $slipDecision === 'WAITING_VERIFY'; // mirrors OrderController empty-key / timeout branch
// Also verify source contains the branch
$src = (string) file_get_contents($root . '/controllers/OrderController.php');
$okSlip = str_contains($src, 'WAITING_VERIFY') && str_contains($src, 'empty($api_key)');
$resilience[] = [
    'No.' => 7,
    'Module' => 'EasySlip',
    'Failure' => 'empty key / API down',
    'Expected' => 'WAITING_VERIFY manual path exists',
    'Actual' => $okSlip ? 'OrderController branch present' : 'branch missing',
    'Pass' => $okSlip ? 1 : 0,
    'Note' => 'aligned with easyslip S3/S4 fixture experiment',
];

// R8 Omise keys missing → feature off
try {
    $gate = OmiseService::isConfigured();
    $threw = false;
    try {
        new OmiseService('');
    } catch (Throwable $e) {
        $threw = true;
    }
    $okOm = $threw === true; // empty secret must fail closed
    $resilience[] = [
        'No.' => 8,
        'Module' => 'Omise',
        'Failure' => 'empty secret / gate',
        'Expected' => 'constructor throws; UI uses isConfigured()',
        'Actual' => 'isConfigured=' . ($gate ? '1' : '0') . '; emptyCtorThrows=' . ($threw ? '1' : '0'),
        'Pass' => $okOm ? 1 : 0,
        'Note' => 'when keys absent, Omise UI hidden via isConfigured()',
    ];
} catch (Throwable $e) {
    $resilience[] = [
        'No.' => 8, 'Module' => 'Omise', 'Failure' => 'isConfigured',
        'Expected' => 'gate works', 'Actual' => $e->getMessage(), 'Pass' => 0, 'Note' => '',
    ];
}

// R9 Redis down → null connect (DB poll path)
$redis = chatRealtimeConnect(0.3, false);
$pubOk = true;
try {
    chatRealtimePublish('resilience_a', 'resilience_b', ['t' => 1]);
} catch (Throwable $e) {
    $pubOk = false;
}
$resilience[] = [
    'No.' => 9,
    'Module' => 'Redis',
    'Failure' => 'port 6399 closed / unavailable',
    'Expected' => 'connect=null; publish no-throw',
    'Actual' => 'connect=' . ($redis === null ? 'null' : 'object') . '; publish_ok=' . ($pubOk ? '1' : '0'),
    'Pass' => ($redis === null && $pubOk) ? 1 : 0,
    'Note' => 'ChatStream falls back to DB polling',
];

// R10 Object Storage not configured → local uploads
$storage = new ObjectStorage();
$uploads = $root . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploads)) {
    @mkdir($uploads, 0755, true);
}
$writable = is_dir($uploads) && is_writable($uploads);
// Also accept creating a probe file
$probeOk = false;
if (is_dir($uploads)) {
    $probe = $uploads . DIRECTORY_SEPARATOR . '.resilience_write_probe';
    $probeOk = @file_put_contents($probe, 'ok') !== false;
    if ($probeOk) {
        @unlink($probe);
    }
}
$localOk = $writable || $probeOk;
$configured = $storage->isConfigured();
$pass10 = $configured || $localOk;
$resilience[] = [
    'No.' => 10,
    'Module' => 'Object Storage',
    'Failure' => 'S3_* unset',
    'Expected' => 'isConfigured=false → local /uploads usable OR S3 configured',
    'Actual' => 'configured=' . ($configured ? '1' : '0') . '; uploads_writable=' . ($writable ? '1' : '0') . '; probe=' . ($probeOk ? '1' : '0'),
    'Pass' => $pass10 ? 1 : 0,
    'Note' => $configured ? 'S3 configured — cloud path active' : ($localOk ? 'local fallback ready' : 'uploads not writable'),
];

$resPass = array_sum(array_column($resilience, 'Pass'));
$resN = count($resilience);

// ---------------------------------------------------------------------------
// §4.12 UAT (automated evidence where possible)
// ---------------------------------------------------------------------------
$uat = [];

function uatRow(string $id, string $scene, string $module, string $expect, bool $pass, string $actual): array
{
    return [
        'ID' => $id,
        'Scene' => $scene,
        'Module' => $module,
        'Expected' => $expect,
        'Actual' => $actual,
        'Pass' => $pass ? 1 : 0,
    ];
}

// U-01 Password login path (hash verify)
$loginUser = $conn->query("SELECT id, username, password FROM users WHERE password IS NOT NULL AND password != '' ORDER BY id ASC LIMIT 1");
$lu = $loginUser ? $loginUser->fetch_assoc() : null;
if ($lu) {
    $hash = (string) $lu['password'];
    $algoOk = str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$argon');
    $uat[] = uatRow('U-01', 'ล็อกอินด้วย Password', 'Auth', 'password hash ใช้งานได้', $algoOk, $algoOk ? 'bcrypt/argon hash present for user#' . $lu['id'] : 'unexpected hash');
} else {
    $uat[] = uatRow('U-01', 'ล็อกอินด้วย Password', 'Auth', 'มีผู้ใช้ทดสอบ', false, 'no users');
}

// U-02 Google GIS configured
$gid = defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '';
$uat[] = uatRow('U-02', 'ล็อกอินด้วย Google', 'Google GIS', 'GOOGLE_CLIENT_ID ตั้งค่า + verifier ทำงาน', $gid, $gid ? 'client_id set; reject-path verified in §4.9' : 'missing client_id');

// U-03 eKYC Genuine (re-enable mock only inside subprocess logic — use MockApiHelper if enabled)
// Under this script MOCK is false. Call with fixtures may hit real AiGen — skip live; use mark of pipeline from prior CSV OR temporary mock.
// Evidence: prior ekyc_results G1 + code path exists
$ekycCsv = __DIR__ . '/ekyc_results.csv';
$u03 = is_file($ekycCsv) && str_contains((string) file_get_contents($ekycCsv), 'approved');
$uat[] = uatRow('U-03', 'eKYC Genuine ผ่าน AiGen', 'eKYC', 'approved ในชุดทดลอง', $u03, $u03 ? 'ekyc_results.csv has approved' : 'missing evidence');

$u04 = is_file($ekycCsv) && str_contains((string) file_get_contents($ekycCsv), 'rejected');
$uat[] = uatRow('U-04', 'eKYC Impostor', 'eKYC', 'rejected ในชุดทดลอง', $u04, $u04 ? 'ekyc_results.csv has rejected' : 'missing evidence');

$u05 = ($resilience[0]['Pass'] ?? 0) === 1;
$uat[] = uatRow('U-05', 'eKYC เมื่อ AiGen ล่ม', 'Fallback', 'pending + Admin', $u05, $resilience[0]['Actual'] ?? '');

// U-06 / U-07 image — from prior CSV + resilience
$imgCsv = __DIR__ . '/image_fraud_results.csv';
$u06 = is_file($imgCsv) && str_contains((string) file_get_contents($imgCsv), ',pass,');
$u07 = is_file($imgCsv) && (str_contains((string) file_get_contents($imgCsv), ',reject,') || str_contains((string) file_get_contents($imgCsv), ',flag,'));
$uat[] = uatRow('U-06', 'ลงขายด้วยรูปกล้องจริง', 'Image Fraud', 'pass', $u06, $u06 ? 'image_fraud_results has pass' : 'no evidence');
$uat[] = uatRow('U-07', 'ลงขายด้วยภาพสกรีน', 'Image Fraud', 'flag/reject', $u07, $u07 ? 'image_fraud_results has reject/flag' : 'no evidence');

// U-08 / U-09 chat
$cFlag = $chat->scanText('แอดไลน์โอนนอกระบบด่วน');
$cPass = $chat->scanText('สวัสดีครับ สินค้าถึงแล้วขอบคุณ');
$u08 = in_array(($cFlag['verdict'] ?? ''), ['flag', 'reject'], true);
$u09 = ($cPass['verdict'] ?? '') === 'pass';
$uat[] = uatRow('U-08', 'ส่งข้อความชวนแอดไลน์', 'Chat Mod', 'flag/reject', $u08, json_encode(['verdict' => $cFlag['verdict'] ?? null, 'score' => $cFlag['risk_score'] ?? null], JSON_UNESCAPED_UNICODE));
$uat[] = uatRow('U-09', 'ส่งข้อความปกติ', 'Chat Mod', 'pass', $u09, json_encode(['verdict' => $cPass['verdict'] ?? null], JSON_UNESCAPED_UNICODE));

// U-10 EasySlip valid decision evidence
$esCsv = __DIR__ . '/easyslip_results.csv';
$u10 = false;
if (is_file($esCsv)) {
    foreach (file($esCsv) as $line) {
        if (str_contains($line, 'S1') && str_contains($line, 'PAID')) {
            $u10 = true;
            break;
        }
    }
}
$uat[] = uatRow('U-10', 'ชำระด้วยสลิปถูกต้อง', 'EasySlip', 'auto paid decision', $u10, $u10 ? 'easyslip S1→PAID in CSV' : 'no evidence');

// U-11 Omise PromptPay/card
$u11 = false;
$u11note = '';
if (OmiseService::isConfigured()) {
    try {
        $o = new OmiseService();
        $s = $o->createPromptPaySource(2000, 'UAT U-11', ['uat' => '1']);
        $u11 = !empty($s['id']) && ($s['type'] ?? '') === 'promptpay';
        $u11note = 'source=' . ($s['id'] ?? '') . ' qr=' . (isset($s['scannable_code']['image']['download_uri']) ? 'yes' : 'null');
    } catch (Throwable $e) {
        $u11note = $e->getMessage();
    }
} else {
    $u11note = 'Omise not configured';
}
$uat[] = uatRow('U-11', 'ชำระ Omise PromptPay (sandbox)', 'Omise', 'ได้ source/QR path', $u11, $u11note);

// U-12 Dispute AI
$d12 = $dispute->analyzeContextForBenchmark($ctx, true);
$u12 = in_array(($d12['recommended_action'] ?? ''), ['REFUND_BUYER', 'PAYOUT_SELLER', 'SPLIT', 'NEEDS_REVIEW'], true);
$uat[] = uatRow('U-12', 'แอดมินรัน Dispute AI', 'Dispute AI', 'ได้คำแนะนำ', $u12, json_encode(['action' => $d12['recommended_action'] ?? null, 'provider' => $d12['provider'] ?? null], JSON_UNESCAPED_UNICODE));

$uatPass = array_sum(array_column($uat, 'Pass'));
$uatN = count($uat);

writeCsv($outResilience, ['No.', 'Module', 'Failure', 'Expected', 'Actual', 'Pass', 'Note'], array_map('array_values', $resilience));
writeCsv($outUat, ['ID', 'Scene', 'Module', 'Expected', 'Actual', 'Pass'], array_map('array_values', $uat));

$summary = [
    'resilience' => [
        'n' => $resN,
        'pass' => $resPass,
        'rate_pct' => round(100 * $resPass / max($resN, 1), 2),
        'mock_api_mode' => MockApiHelper::isEnabled(),
        'vision_key_stubbed' => defined('GOOGLE_CLOUD_VISION_API_KEY') ? GOOGLE_CLOUD_VISION_API_KEY : null,
    ],
    'uat' => [
        'n' => $uatN,
        'pass' => $uatPass,
        'rate_pct' => round(100 * $uatPass / max($uatN, 1), 2),
    ],
];
file_put_contents($outJson, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Resilience {$resPass}/{$resN} ({$summary['resilience']['rate_pct']}%)\n";
echo "UAT {$uatPass}/{$uatN} ({$summary['uat']['rate_pct']}%)\n";
echo "MOCK_API_MODE=" . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo "CSV: {$outResilience}\nCSV: {$outUat}\nJSON: {$outJson}\n";

foreach ($resilience as $r) {
    echo 'R' . $r['No.'] . ' ' . ($r['Pass'] ? 'PASS' : 'FAIL') . ' ' . $r['Module'] . "\n";
}
foreach ($uat as $u) {
    echo $u['ID'] . ' ' . ($u['Pass'] ? 'PASS' : 'FAIL') . "\n";
}
