<?php
/**
 * Real eKYC pair test — reads data_real/ekyc/{genuine,impostor}/*_{id,selfie}.jpg
 *
 * Forces MOCK_API_MODE=false so AiGen is called for real.
 *
 * Usage:
 *   C:\xampp\php\php.exe tests/test_ekyc_real_pairs.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// Force live API for this script (do not rely on .env mock flag)
putenv('MOCK_API_MODE=false');
$_ENV['MOCK_API_MODE'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/EkycService.php';

$base = $root . DIRECTORY_SEPARATOR . 'data_real' . DIRECTORY_SEPARATOR . 'ekyc';
// Optional: php tests/test_ekyc_real_pairs.php data_real/ekyc_resized
if (isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '') {
    $candidate = $argv[1];
    if (!preg_match('/^[\/\\\\]/', $candidate) && !preg_match('/^[A-Za-z]:[\/\\\\]/', $candidate)) {
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
    }
    if (is_dir($candidate)) {
        $base = $candidate;
    } else {
        fwrite(STDERR, "Directory not found: {$argv[1]}\n");
        exit(1);
    }
}
$outCsv = __DIR__ . DIRECTORY_SEPARATOR . 'ekyc_real_results.csv';
$outJson = __DIR__ . DIRECTORY_SEPARATOR . 'ekyc_real_summary.json';
if (str_contains(str_replace('\\', '/', $base), 'ekyc_resized')) {
    $outCsv = __DIR__ . DIRECTORY_SEPARATOR . 'ekyc_real_resized_results.csv';
    $outJson = __DIR__ . DIRECTORY_SEPARATOR . 'ekyc_real_resized_summary.json';
}

/**
 * Discover pairs: prefix_id.jpg + prefix_selfie.jpg in a folder.
 *
 * @return list<array{id:string,prefix:string,id_path:string,selfie_path:string,group:string,gt:int}>
 */
function discoverPairs(string $dir, string $group, int $gt): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $ids = glob($dir . DIRECTORY_SEPARATOR . '*_id.jpg') ?: [];
    $ids = array_merge($ids, glob($dir . DIRECTORY_SEPARATOR . '*_id.JPG') ?: []);
    $ids = array_merge($ids, glob($dir . DIRECTORY_SEPARATOR . '*_id.jpeg') ?: []);
    $pairs = [];
    foreach ($ids as $idPath) {
        $base = basename($idPath);
        if (!preg_match('/^(.+)_id\.(jpe?g)$/i', $base, $m)) {
            continue;
        }
        $prefix = $m[1];
        $ext = $m[2];
        $selfie = $dir . DIRECTORY_SEPARATOR . $prefix . '_selfie.' . $ext;
        if (!is_file($selfie)) {
            // try alternate casing/extensions
            foreach (['jpg', 'JPG', 'jpeg', 'JPEG'] as $e) {
                $alt = $dir . DIRECTORY_SEPARATOR . $prefix . '_selfie.' . $e;
                if (is_file($alt)) {
                    $selfie = $alt;
                    break;
                }
            }
        }
        if (!is_file($selfie)) {
            fwrite(STDERR, "SKIP {$prefix}: missing selfie\n");
            continue;
        }
        $pairs[] = [
            'id' => $prefix,
            'prefix' => $prefix,
            'id_path' => $idPath,
            'selfie_path' => $selfie,
            'group' => $group,
            'gt' => $gt,
        ];
    }
    usort($pairs, static fn($a, $b) => strcmp($a['prefix'], $b['prefix']));
    return $pairs;
}

$pairs = array_merge(
    discoverPairs($base . DIRECTORY_SEPARATOR . 'genuine', 'G1', 1),
    discoverPairs($base . DIRECTORY_SEPARATOR . 'impostor', 'G2', 0)
);

if ($pairs === []) {
    fwrite(STDERR, "No pairs found under {$base}\n");
    exit(1);
}

if (!EkycService::isAigenConfigured()) {
    fwrite(STDERR, "AIGEN_API_KEY is not configured — cannot run live eKYC.\n");
    exit(1);
}

if (MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK still enabled after force-off. Abort.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, 'DB failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$ekyc = new EkycService($conn);
$userId = 0;

$fp = fopen($outCsv, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, [
    'No.', 'Pair ID', 'Group', 'GT Match (1/0)', 'OCR Pass (1/0)', 'Face Score',
    'System Match (1/0)', 'KYC Status', 'OCR ms', 'Face ms', 'E2E ms',
    'Correct (1/0)', 'ID file', 'Selfie file', 'Note',
]);

echo "=== Real eKYC pairs ===\n";
echo 'Pairs=' . count($pairs) . " MOCK=" . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo "Output: {$outCsv}\n\n";

$tp = $tn = $fpCount = $fn = 0;
$ocrFail = 0;
$latencies = [];
$rowsOut = [];

foreach ($pairs as $i => $pair) {
    $no = $i + 1;
    $gt = $pair['gt'];
    echo "[{$no}/" . count($pairs) . "] {$pair['prefix']} ({$pair['group']}) ... ";

    try {
        $result = $ekyc->verifyWithAigen($userId, $pair['id_path'], $pair['selfie_path'], false);
        $note = (string) ($result['message'] ?? '');
    } catch (Throwable $e) {
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

    $ocrPass = !empty($result['ocr_pass']) ? 1 : 0;
    $systemMatch = (int) ($result['system_match'] ?? 0);
    $faceScore = $result['score'] !== null ? round((float) $result['score'], 2) : '';
    $kycStatus = (string) ($result['status'] ?? '');
    $ocrMs = (int) ($result['ocr_ms'] ?? 0);
    $faceMs = (int) ($result['face_ms'] ?? 0);
    $e2eMs = (int) ($result['e2e_ms'] ?? 0);
    $latencies[] = $e2eMs;

    $correct = 0;
    if ($ocrPass === 1) {
        if ($gt === 1 && $systemMatch === 1) {
            $tp++;
            $correct = 1;
        } elseif ($gt === 0 && $systemMatch === 0) {
            $tn++;
            $correct = 1;
        } elseif ($gt === 0 && $systemMatch === 1) {
            $fpCount++;
            $correct = 0;
        } elseif ($gt === 1 && $systemMatch === 0) {
            $fn++;
            $correct = 0;
        }
    } else {
        $ocrFail++;
        // OCR fail: Genuine expected pass → wrong; Impostor expected reject → treat as not correct for match task
        $correct = 0;
        $note = ($note !== '' ? $note . ' | ' : '') . 'OCR_FAIL';
    }

    $row = [
        $no,
        $pair['prefix'],
        $pair['group'],
        $gt,
        $ocrPass,
        $faceScore,
        $systemMatch,
        $kycStatus,
        $ocrMs,
        $faceMs,
        $e2eMs,
        $correct,
        basename($pair['id_path']),
        basename($pair['selfie_path']),
        $note,
    ];
    fputcsv($fp, $row);
    $rowsOut[] = $row;

    echo "ocr={$ocrPass} match={$systemMatch} score={$faceScore} status={$kycStatus} correct={$correct} e2e={$e2eMs}ms\n";
    usleep(300_000);
}

fclose($fp);
mysqli_close($conn);

$nDecided = $tp + $tn + $fpCount + $fn;
$acc = $nDecided > 0 ? round(100 * ($tp + $tn) / $nDecided, 2) : 0.0;
$accAll = count($pairs) > 0 ? round(100 * ($tp + $tn) / count($pairs), 2) : 0.0;
sort($latencies);
$avg = $latencies ? (int) round(array_sum($latencies) / count($latencies)) : 0;
$p95 = $latencies ? $latencies[(int) ceil(0.95 * count($latencies)) - 1] : 0;

$summary = [
    'n_pairs' => count($pairs),
    'tp' => $tp,
    'tn' => $tn,
    'fp' => $fpCount,
    'fn' => $fn,
    'ocr_fail' => $ocrFail,
    'accuracy_among_ocr_pass_pct' => $acc,
    'accuracy_all_pairs_pct' => $accAll,
    'avg_e2e_ms' => $avg,
    'p95_e2e_ms' => $p95,
    'mock_api_mode' => false,
    'subjects' => '2 people (A/B) genuine + swapped impostor',
    'csv' => 'tests/ekyc_real_results.csv',
];
file_put_contents($outJson, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n=== Summary ===\n";
echo "TP={$tp} TN={$tn} FP={$fpCount} FN={$fn} OCR_fail={$ocrFail}\n";
echo "Accuracy (OCR-pass only)={$acc}% | Accuracy (all pairs)={$accAll}%\n";
echo "Avg E2E={$avg}ms P95={$p95}ms\n";
echo "CSV={$outCsv}\nJSON={$outJson}\n";
