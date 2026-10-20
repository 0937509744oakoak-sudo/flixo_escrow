<?php
/**
 * Real Product Image Fraud eval — scans data_real/image_fraud/{clean,risky}
 *
 * Forces MOCK_API_MODE=false (EXIF + Cloud Vision live when configured).
 *
 * Usage:
 *   C:\xampp\php\php.exe tests/test_image_fraud_real.php
 *   C:\xampp\php\php.exe tests/test_image_fraud_real.php --count-only
 *   C:\xampp\php\php.exe tests/test_image_fraud_real.php data_real/image_fraud
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$countOnly = in_array('--count-only', $argv, true);
$argv = array_values(array_filter($argv, static fn($a) => $a !== '--count-only'));

putenv('MOCK_API_MODE=false');
$_ENV['MOCK_API_MODE'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/ProductImageFraudService.php';

$base = $root . DIRECTORY_SEPARATOR . 'data_real' . DIRECTORY_SEPARATOR . 'image_fraud';
if (isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '') {
    $candidate = $argv[1];
    if (!preg_match('/^[\/\\\\]/', $candidate) && !preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate)) {
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    }
    if (!is_dir($candidate)) {
        fwrite(STDERR, "Directory not found: {$argv[1]}\n");
        exit(1);
    }
    $base = $candidate;
}

$cleanDir = $base . DIRECTORY_SEPARATOR . 'clean';
$riskyDir = $base . DIRECTORY_SEPARATOR . 'risky';

/**
 * @return list<string>
 */
function listImages(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (['jpg', 'JPG', 'jpeg', 'JPEG', 'png', 'PNG', 'webp', 'WEBP'] as $ext) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $f) {
            $out[] = $f;
        }
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

$clean = listImages($cleanDir);
$risky = listImages($riskyDir);
$nAll = count($clean) + count($risky);

echo "=== Image Fraud REAL ===\n";
echo "Base: {$base}\n";
echo "clean=" . count($clean) . " risky=" . count($risky) . " total={$nAll}\n";
echo "MOCK=" . (MockApiHelper::isEnabled() ? 'ON (unexpected!)' : 'off') . "\n";
echo "Vision key: " . (ProductImageFraudService::isCloudVisionConfigured() ? 'yes' : 'NO') . "\n";
echo "Fraud enabled: " . (ProductImageFraudService::isEnabled() ? 'yes' : 'NO') . "\n";

if ($countOnly) {
    if ($nAll < 20) {
        echo "Need more images (target 20–30). See data_real/image_fraud/README.md\n";
        exit(2);
    }
    echo "Count OK (≥20).\n";
    exit(0);
}

if ($nAll === 0) {
    fwrite(STDERR, "No images found. Put camera photos in clean/ then run:\n");
    fwrite(STDERR, "  C:\\xampp\\php\\php.exe scripts\\prepare_image_fraud_real.php\n");
    exit(1);
}
if ($nAll < 10) {
    fwrite(STDERR, "Warning: only {$nAll} images (recommended 20–30).\n");
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$svc = new ProductImageFraudService($conn);
$blockOn = !defined('IMAGE_FRAUD_BLOCK') || IMAGE_FRAUD_BLOCK;

$cases = [];
foreach ($clean as $path) {
    $cases[] = ['group' => 'P1_clean', 'gt' => 0, 'path' => $path];
}
foreach ($risky as $path) {
    $cases[] = ['group' => 'P2_risky', 'gt' => 1, 'path' => $path];
}

$outCsv = __DIR__ . DIRECTORY_SEPARATOR . 'image_fraud_real_results.csv';
$outJson = __DIR__ . DIRECTORY_SEPARATOR . 'image_fraud_real_summary.json';
$fp = fopen($outCsv, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, [
    'No.', 'Group', 'Filename', 'GT Risky (1/0)', 'EXIF Trust', 'Suspicious?',
    'Vision Used (0/1)', 'Risk Score', 'Verdict', 'Blocked?', 'Latency ms', 'Provider', 'Note',
]);

$tp = $tn = $fpCount = $fn = 0;
$latencies = [];
$rowsOut = [];
$no = 0;

foreach ($cases as $case) {
    $no++;
    $path = $case['path'];
    $gt = $case['gt'];
    $t0 = microtime(true);
    $result = $svc->scanImages([$path], [
        'source' => 'real_eval',
        'product_name' => 'FLIXO real image fraud eval',
        'category' => 'อื่นๆ',
        'description' => 'ชุดทดลองภาพจริง — ไม่ใช่ประกาศขาย',
    ]);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $latencies[] = $ms;

    $per = $result['per_image'][0] ?? $result;
    $risk = (float) ($result['risk_score'] ?? $per['risk_score'] ?? 0);
    $verdict = (string) ($result['verdict'] ?? $per['verdict'] ?? 'pass');
    $provider = (string) ($result['provider'] ?? $per['provider'] ?? '');
    $raw = $per['raw'] ?? [];
    $exif = $raw['exif'] ?? [];
    $exifTrust = $exif['trust_score'] ?? '';
    $suspicious = !empty($exif['suspicious']) ? 1 : ((!empty($raw['vision_skipped'])) ? 0 : 1);
    $visionUsed = (!empty($raw['tier']) && (int) $raw['tier'] >= 2)
        || str_contains($provider, 'cloud_vision')
        || (isset($raw['vision_skipped']) && $raw['vision_skipped'] === false)
        ? 1 : 0;
    if (!empty($raw['vision_skipped'])) {
        $visionUsed = 0;
    }
    $blocked = ($blockOn && $verdict === ProductImageFraudService::VERDICT_REJECT) ? '1' : '0';
    $predRisky = in_array($verdict, ['flag', 'reject'], true) ? 1 : 0;
    $reasons = implode('; ', $result['reasons'] ?? $per['reasons'] ?? []);
    $ok = ($gt === $predRisky);
    $note = ($ok ? 'OK' : 'MISS') . ' | pred=' . $predRisky . ($reasons !== '' ? ' | ' . $reasons : '');

    if ($gt === 1 && $predRisky === 1) {
        $tp++;
    } elseif ($gt === 0 && $predRisky === 0) {
        $tn++;
    } elseif ($gt === 0 && $predRisky === 1) {
        $fpCount++;
    } else {
        $fn++;
    }

    $row = [
        $no,
        $case['group'],
        basename($path),
        $gt,
        $exifTrust,
        $suspicious,
        $visionUsed,
        $risk,
        $verdict,
        $blocked,
        $ms,
        $provider,
        $note,
    ];
    fputcsv($fp, $row);
    $rowsOut[] = $row;
    echo sprintf(
        "  [%d/%d] %s gt=%d → %s risk=%.0f ms=%d%s\n",
        $no,
        $nAll,
        basename($path),
        $gt,
        $verdict,
        $risk,
        $ms,
        $ok ? '' : '  << MISS'
    );
}
fclose($fp);
mysqli_close($conn);

$n = max(1, $tp + $tn + $fpCount + $fn);
$acc = ($tp + $tn) / $n;
sort($latencies);
$avg = $latencies === [] ? 0 : array_sum($latencies) / count($latencies);
$p95 = $latencies === [] ? 0 : $latencies[(int) max(0, (int) ceil(0.95 * count($latencies)) - 1)];

$summary = [
    'n' => $n,
    'n_clean' => count($clean),
    'n_risky' => count($risky),
    'tp' => $tp,
    'tn' => $tn,
    'fp' => $fpCount,
    'fn' => $fn,
    'accuracy_pct' => round(100 * $acc, 2),
    'precision' => ($tp + $fpCount) > 0 ? round($tp / ($tp + $fpCount), 4) : null,
    'recall' => ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 4) : null,
    'avg_ms' => (int) round($avg),
    'p95_ms' => (int) $p95,
    'mock_api_mode' => MockApiHelper::isEnabled(),
    'vision_configured' => ProductImageFraudService::isCloudVisionConfigured(),
    'csv' => 'tests/image_fraud_real_results.csv',
];
file_put_contents($outJson, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

$appendix = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'appendix_ch4';
if (is_dir($appendix)) {
    copy($outCsv, $appendix . DIRECTORY_SEPARATOR . 'image_fraud_real_results.csv');
    copy($outJson, $appendix . DIRECTORY_SEPARATOR . 'image_fraud_real_summary.json');
}

echo "Done {$outCsv}\n";
echo "Acc=" . $summary['accuracy_pct'] . "% TP={$tp} TN={$tn} FP={$fpCount} FN={$fn} ";
echo "Avg={$summary['avg_ms']} P95={$summary['p95_ms']} ms\n";
echo "Summary {$outJson}\n";
