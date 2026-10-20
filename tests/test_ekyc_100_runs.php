<?php
/**
 * Automated eKYC load test — 100 runs (Genuine 50 / Impostor 50)
 * Exports tests/ekyc_results.csv with headers matching Chapter 4 §4.3.2
 *
 * Usage (from project root):
 *   C:\xampp\php\php.exe tests/test_ekyc_100_runs.php
 *
 * Requires MOCK_API_MODE=true in .env to avoid burning AiGen quota.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/EkycService.php';

const TOTAL_RUNS = 100;
const GENUINE_RUNS = 50;
const IMPOSTOR_RUNS = 50;

$csvPath = __DIR__ . '/ekyc_results.csv';
$idCardPath = __DIR__ . '/fixtures_id.jpg';
$selfiePath = __DIR__ . '/fixtures_selfie.jpg';

if (!is_file($idCardPath) || !is_file($selfiePath)) {
    fwrite(STDERR, "Missing fixture images under tests/. Re-run setup or create fixtures_id.jpg / fixtures_selfie.jpg\n");
    exit(1);
}

if (!MockApiHelper::isEnabled()) {
    fwrite(STDERR, "WARNING: MOCK_API_MODE is OFF — this will call the real AiGen API.\n");
    fwrite(STDERR, "Set MOCK_API_MODE=true in .env before load testing.\n");
}

// Lightweight mysqli (avoid db.php session bootstrap for CLI)
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, 'Database connection failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$ekyc = new EkycService($conn);
$userId = 0; // persistResult=false → no DB KYC writes

/** @var list<array{group:string,gt:int}> $plan */
$plan = [];
for ($i = 0; $i < GENUINE_RUNS; $i++) {
    $plan[] = ['group' => 'G1', 'gt' => 1];
}
for ($i = 0; $i < IMPOSTOR_RUNS; $i++) {
    $plan[] = ['group' => 'G2', 'gt' => 0];
}
shuffle($plan);

$headers = [
    'No.',
    'Group',
    'GT Match (1/0)',
    'OCR Pass (1/0)',
    'Face Score',
    'System Match (1/0)',
    'KYC Status',
    'OCR ms',
    'Face ms',
    'E2E ms',
    'Device',
    'Light',
    'Note',
];

$fp = fopen($csvPath, 'w');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$csvPath}\n");
    exit(1);
}
// UTF-8 BOM for Excel
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $headers);

$device = 'CLI-LoadTest / fixtures JPEG';
$light = 'Good';
$tp = $tn = $fpCount = $fn = 0;
$sumE2e = 0;

echo "=== eKYC 100-run load test ===\n";
echo 'MOCK_API_MODE=' . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo 'Output: ' . $csvPath . "\n\n";

foreach ($plan as $idx => $case) {
    $no = $idx + 1;
    $gtMatch = $case['gt'];
    $group = $case['group'];

    // Drive mock GT: Genuine → match, Impostor → non-match (OCR always success)
    MockApiHelper::setEkycScenario($gtMatch === 1, true);

    try {
        $result = $ekyc->verifyWithAigen($userId, $idCardPath, $selfiePath, false);
        $note = (string) ($result['message'] ?? '');
    } catch (Throwable $e) {
        MockApiHelper::clearScenario();
        $result = [
            'status' => 'error',
            'score' => null,
            'ocr_pass' => false,
            'system_match' => 0,
            'ocr_ms' => 0,
            'face_ms' => 0,
            'e2e_ms' => 0,
        ];
        $note = 'EXCEPTION: ' . $e->getMessage();
    }

    MockApiHelper::clearScenario();

    $ocrPass = !empty($result['ocr_pass']) ? 1 : 0;
    $systemMatch = (int) ($result['system_match'] ?? 0);
    $faceScore = $result['score'] !== null ? round((float) $result['score'], 2) : '';
    $kycStatus = (string) ($result['status'] ?? '');
    $ocrMs = (int) ($result['ocr_ms'] ?? 0);
    $faceMs = (int) ($result['face_ms'] ?? 0);
    $e2eMs = (int) ($result['e2e_ms'] ?? 0);
    $sumE2e += $e2eMs;

    // Confusion counts (only when OCR passed so face decision is meaningful)
    if ($ocrPass === 1) {
        if ($gtMatch === 1 && $systemMatch === 1) {
            $tp++;
        } elseif ($gtMatch === 0 && $systemMatch === 0) {
            $tn++;
        } elseif ($gtMatch === 0 && $systemMatch === 1) {
            $fpCount++;
        } elseif ($gtMatch === 1 && $systemMatch === 0) {
            $fn++;
        }
    }

    $row = [
        $no,
        $group,
        $gtMatch,
        $ocrPass,
        $faceScore,
        $systemMatch,
        $kycStatus,
        $ocrMs,
        $faceMs,
        $e2eMs,
        $device,
        $light,
        $note,
    ];
    fputcsv($fp, $row);

    if ($no % 10 === 0 || $no === TOTAL_RUNS) {
        echo sprintf("  [%3d/%d] group=%s gt=%d match=%d e2e=%dms status=%s\n",
            $no, TOTAL_RUNS, $group, $gtMatch, $systemMatch, $e2eMs, $kycStatus);
    }
}

fclose($fp);
mysqli_close($conn);

$nDecided = $tp + $tn + $fpCount + $fn;
$accuracy = $nDecided > 0 ? round(100 * ($tp + $tn) / $nDecided, 2) : 0;
$avgE2e = TOTAL_RUNS > 0 ? (int) round($sumE2e / TOTAL_RUNS) : 0;

echo "\nDone. Wrote {$csvPath}\n";
echo "Confusion (OCR-passed only): TP={$tp} TN={$tn} FP={$fpCount} FN={$fn}\n";
echo "Accuracy≈{$accuracy}% | Avg E2E={$avgE2e} ms\n";
