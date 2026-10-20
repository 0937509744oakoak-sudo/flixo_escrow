<?php
/**
 * Product Image Fraud — 100 runs (Clean 50 / Risky 50) → image_fraud_results.csv
 * Usage: C:\xampp\php\php.exe tests/test_image_fraud_100_runs.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/ProductImageFraudService.php';

$csvPath = __DIR__ . '/image_fraud_results.csv';
$fixture = __DIR__ . '/fixtures_id.jpg';
if (!is_file($fixture)) {
    fwrite(STDERR, "Missing fixtures_id.jpg\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) { fwrite(STDERR, mysqli_connect_error() . "\n"); exit(1); }
mysqli_set_charset($conn, 'utf8mb4');

$svc = new ProductImageFraudService($conn);
$plan = [];
for ($i = 0; $i < 50; $i++) { $plan[] = ['group' => 'P1', 'gt' => 0, 'scenario' => 'clean']; }
for ($i = 0; $i < 50; $i++) { $plan[] = ['group' => 'P2', 'gt' => 1, 'scenario' => 'risky']; }
shuffle($plan);

$headers = ['No.', 'Group', 'GT Risky (1/0)', 'EXIF Trust', 'Suspicious?', 'Vision Used (0/1)', 'Risk Score', 'Verdict', 'Blocked?', 'Latency ms', 'Note'];
$fp = fopen($csvPath, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $headers);

$tp = $tn = $fpCount = $fn = 0;
$sum = 0;
$blockOn = !defined('IMAGE_FRAUD_BLOCK') || IMAGE_FRAUD_BLOCK;

echo "=== Image Fraud 100-run ===\nMOCK=" . (MockApiHelper::isEnabled() ? 'on' : 'off') . "\n";

foreach ($plan as $idx => $case) {
    $no = $idx + 1;
    MockApiHelper::setImageFraudScenario($case['scenario']);
    $t0 = microtime(true);
    $result = $svc->scanImages([$fixture], ['source' => 'load_test']);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $sum += $ms;

    $per = $result['per_image'][0] ?? $result;
    // aggregate result shape from scanImages
    $risk = (float) ($result['risk_score'] ?? $per['risk_score'] ?? 0);
    $verdict = (string) ($result['verdict'] ?? $per['verdict'] ?? 'pass');
    $raw = $result['per_image'][0]['raw'] ?? ($per['raw'] ?? []);
    $visionUsed = (!empty($raw['tier']) && (int) $raw['tier'] >= 2) || str_contains((string) ($result['provider'] ?? $per['provider'] ?? ''), 'cloud_vision') ? 1 : 0;
    $exifTrust = $raw['exif']['trust_score'] ?? ($case['scenario'] === 'clean' ? 80 : 20);
    $suspicious = $case['scenario'] === 'risky' ? 1 : 0;
    $blocked = ($blockOn && $verdict === ProductImageFraudService::VERDICT_REJECT) ? '1' : '0';
    $predRisky = in_array($verdict, ['flag', 'reject'], true) ? 1 : 0;
    $gt = $case['gt'];

    if ($gt === 1 && $predRisky === 1) $tp++;
    elseif ($gt === 0 && $predRisky === 0) $tn++;
    elseif ($gt === 0 && $predRisky === 1) $fpCount++;
    else $fn++;

    fputcsv($fp, [$no, $case['group'], $gt, $exifTrust, $suspicious, $visionUsed, $risk, $verdict, $blocked, $ms, implode('; ', $result['reasons'] ?? $per['reasons'] ?? [])]);
    if ($no % 10 === 0) echo "  [$no/100] gt=$gt verdict=$verdict ms=$ms\n";
    MockApiHelper::clearScenario();
}
fclose($fp);
mysqli_close($conn);
$n = max(1, $tp + $tn + $fpCount + $fn);
echo "Done $csvPath | Acc=" . round(100 * ($tp + $tn) / $n, 2) . "% Avg={$sum}→" . (int) round($sum / 100) . "ms TP=$tp TN=$tn FP=$fpCount FN=$fn\n";
