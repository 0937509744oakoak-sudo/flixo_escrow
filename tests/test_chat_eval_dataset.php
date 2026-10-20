<?php
/**
 * Flexible Chat Moderation dataset eval (Mock or Live).
 *
 * Usage:
 *   php tests/test_chat_eval_dataset.php --dataset=tests/chat_moderation_dataset_120.csv --mode=mock
 *   php tests/test_chat_eval_dataset.php --dataset=tests/chat_moderation_dataset_120.csv --mode=live
 *
 * Optional:
 *   --out-csv=tests/chat_120_results.csv
 *   --out-json=tests/chat_120_summary.json
 *   --slice=101-120   (evaluate only this No. range; still writes slice outputs)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = [
    'dataset' => __DIR__ . '/chat_moderation_dataset_120.csv',
    'mode' => 'mock',
    'out-csv' => '',
    'out-json' => '',
    'slice' => '',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$mode = strtolower((string) $args['mode']);
if (!in_array($mode, ['mock', 'live'], true)) {
    fwrite(STDERR, "mode must be mock|live\n");
    exit(1);
}

if ($mode === 'live') {
    putenv('MOCK_API_MODE=false');
    $_ENV['MOCK_API_MODE'] = 'false';
} else {
    putenv('MOCK_API_MODE=true');
    $_ENV['MOCK_API_MODE'] = 'true';
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/GroqService.php';
require_once $root . '/includes/OpenRouterService.php';
require_once $root . '/includes/ChatModerationService.php';

$datasetPath = $args['dataset'];
if ($datasetPath !== '' && !preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $datasetPath)) {
    $datasetPath = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $datasetPath);
}
if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    exit(1);
}

$stem = pathinfo($datasetPath, PATHINFO_FILENAME);
$defaultTag = $mode === 'live' ? 'real' : 'mock';
$outCsv = $args['out-csv'] !== ''
    ? ($args['out-csv'][0] === '/' || preg_match('/^[A-Za-z]:/', $args['out-csv']) ? $args['out-csv'] : $root . DIRECTORY_SEPARATOR . $args['out-csv'])
    : __DIR__ . "/chat_{$stem}_{$defaultTag}_results.csv";
$outJson = $args['out-json'] !== ''
    ? ($args['out-json'][0] === '/' || preg_match('/^[A-Za-z]:/', $args['out-json']) ? $args['out-json'] : $root . DIRECTORY_SEPARATOR . $args['out-json'])
    : __DIR__ . "/chat_{$stem}_{$defaultTag}_summary.json";

if ($mode === 'live' && MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK still on after force-off.\n");
    exit(1);
}
if ($mode === 'live') {
    $hasLlm = GroqService::isConfigured() || OpenRouterService::isConfigured()
        || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
    if (!$hasLlm) {
        fwrite(STDERR, "No LLM API key configured.\n");
        exit(1);
    }
}

/**
 * @return list<array{no:int,group:string,text:string,gt:int}>
 */
function loadChatDatasetEval(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot open dataset: {$path}");
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException('Dataset empty');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string) $name)] = $i;
    }
    foreach (['No.', 'Group', 'Text', 'GT Risky (1/0)'] as $req) {
        if (!isset($map[$req])) {
            fclose($fh);
            throw new RuntimeException("Missing column {$req}");
        }
    }
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if ($r === [null] || count(array_filter($r, static fn($v) => $v !== null && $v !== '')) === 0) {
            continue;
        }
        $rows[] = [
            'no' => (int) ($r[$map['No.']] ?? 0),
            'group' => trim((string) ($r[$map['Group']] ?? '')),
            'text' => (string) ($r[$map['Text']] ?? ''),
            'gt' => (int) ($r[$map['GT Risky (1/0)']] ?? 0),
        ];
    }
    fclose($fh);
    return $rows;
}

$plan = loadChatDatasetEval($datasetPath);
if ($args['slice'] !== '' && preg_match('/^(\d+)-(\d+)$/', (string) $args['slice'], $sm)) {
    $lo = (int) $sm[1];
    $hi = (int) $sm[2];
    $plan = array_values(array_filter($plan, static fn($r) => $r['no'] >= $lo && $r['no'] <= $hi));
}
$totalRuns = count($plan);
if ($totalRuns === 0) {
    fwrite(STDERR, "No rows to evaluate.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $moderation = new ChatModerationService($conn);
} else {
    // scanText() does not touch DB; allow offline eval when MySQL is down.
    fwrite(STDERR, 'WARNING: DB unavailable (' . mysqli_connect_error() . ") — using constructor-less service for scan-only eval.\n");
    $ref = new ReflectionClass(ChatModerationService::class);
    $moderation = $ref->newInstanceWithoutConstructor();
    $conn = null;
}
$blockEnabled = defined('CHAT_MODERATION_BLOCK') ? (bool) CHAT_MODERATION_BLOCK : true;
$regexMethod = (new ReflectionClass($moderation))->getMethod('scanTextRegex');
$regexMethod->setAccessible(true);

$fp = fopen($outCsv, 'w');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$outCsv}\n");
    exit(1);
}
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, [
    'No.', 'Group', 'GT Risky (1/0)', 'Regex Score', 'AI Provider Used', 'AI Score',
    'Final Score', 'Verdict', 'Blocked?', 'Latency ms', 'Note',
]);

echo "=== Chat dataset evaluation ===\n";
echo "Mode={$mode} MOCK_API_MODE=" . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo "Dataset={$datasetPath}\n";
echo "Rows={$totalRuns}\n";
echo "Output CSV={$outCsv}\n";
echo "Output JSON={$outJson}\n\n";

$tp = $tn = $fpCount = $fn = 0;
$correct = 0;
$sumLatency = 0;
$latencies = [];
$providers = [];
$misses = [];

foreach ($plan as $idx => $case) {
    $no = $case['no'] > 0 ? $case['no'] : ($idx + 1);
    $gtRisky = $case['gt'];
    $text = $case['text'];

    $t0 = microtime(true);
    try {
        $result = $moderation->scanText($text);
        $note = implode('; ', $result['reasons'] ?? []);
    } catch (Throwable $e) {
        $result = ['provider' => 'error', 'risk_score' => 0, 'verdict' => 'pass', 'raw' => []];
        $note = 'EXCEPTION: ' . $e->getMessage();
    }
    $latencyMs = (int) round((microtime(true) - $t0) * 1000);
    $sumLatency += $latencyMs;
    $latencies[] = $latencyMs;

    $finalScore = (float) ($result['risk_score'] ?? 0);
    $verdict = (string) ($result['verdict'] ?? 'pass');
    $provider = (string) ($result['provider'] ?? '');
    $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
    $aiRaw = is_array($raw['ai'] ?? null) ? $raw['ai'] : $raw;

    $regexOnly = $regexMethod->invoke($moderation, $text);
    $regexScore = (string) ((float) ($regexOnly['risk_score'] ?? 0));

    $aiScore = '';
    $aiProvider = 'none';
    if (($raw['tier'] ?? 1) >= 2 || str_contains($provider, '+')) {
        if (isset($aiRaw['groq']) && is_array($aiRaw['groq'])) {
            $aiProvider = 'groq';
            $aiScore = (string) ($aiRaw['groq']['risk_score'] ?? $finalScore);
        } elseif (isset($aiRaw['openrouter']) && is_array($aiRaw['openrouter'])) {
            $aiProvider = 'openrouter';
            $aiScore = (string) ($aiRaw['openrouter']['risk_score'] ?? $finalScore);
        } elseif (isset($aiRaw['gemini']) && is_array($aiRaw['gemini'])) {
            $aiProvider = 'gemini';
            $aiScore = (string) ($aiRaw['gemini']['risk_score'] ?? $finalScore);
        } elseif (str_contains($provider, 'groq')) {
            $aiProvider = 'groq';
            $aiScore = (string) $finalScore;
        } elseif (str_contains($provider, 'openrouter')) {
            $aiProvider = 'openrouter';
            $aiScore = (string) $finalScore;
        } elseif (str_contains($provider, 'gemini')) {
            $aiProvider = 'gemini';
            $aiScore = (string) $finalScore;
        } else {
            $aiProvider = $provider !== '' ? $provider : 'ai';
            $aiScore = (string) $finalScore;
        }
    }
    $providers[$aiProvider] = ($providers[$aiProvider] ?? 0) + 1;

    $blocked = ($blockEnabled && $verdict === ChatModerationService::VERDICT_REJECT) ? '1' : '0';
    $predictedRisky = in_array($verdict, [
        ChatModerationService::VERDICT_FLAG,
        ChatModerationService::VERDICT_REJECT,
    ], true) ? 1 : 0;

    $isCorrect = ($predictedRisky === $gtRisky) ? 1 : 0;
    $correct += $isCorrect;
    if ($gtRisky === 1 && $predictedRisky === 1) {
        $tp++;
    } elseif ($gtRisky === 0 && $predictedRisky === 0) {
        $tn++;
    } elseif ($gtRisky === 0 && $predictedRisky === 1) {
        $fpCount++;
    } else {
        $fn++;
    }

    if ($note === '') {
        $note = 'preview: ' . mb_substr($text, 0, 40);
    }
    $note = ($isCorrect ? 'OK' : 'MISS') . ' | pred=' . $predictedRisky . ' | ' . $note;
    if (!$isCorrect) {
        $misses[] = [
            'no' => $no,
            'group' => $case['group'],
            'gt' => $gtRisky,
            'verdict' => $verdict,
            'provider' => $aiProvider,
            'text' => mb_substr($text, 0, 80),
            'note' => mb_substr($note, 0, 160),
        ];
    }

    fputcsv($fp, [
        $no, $case['group'], $gtRisky, $regexScore, $aiProvider, $aiScore,
        $finalScore, $verdict, $blocked, $latencyMs, $note,
    ]);

    if (($idx + 1) % 10 === 0 || ($idx + 1) === $totalRuns) {
        echo sprintf(
            "  [%3d/%d] correct=%d/%d TP=%d TN=%d FP=%d FN=%d last_ai=%s last_ms=%d\n",
            $idx + 1,
            $totalRuns,
            $correct,
            $idx + 1,
            $tp,
            $tn,
            $fpCount,
            $fn,
            $aiProvider,
            $latencyMs
        );
    }

    if ($mode === 'live' && $aiProvider !== 'none') {
        usleep(200_000);
    }
}

fclose($fp);
if ($conn instanceof mysqli) {
    mysqli_close($conn);
}

sort($latencies);
$n = max($tp + $tn + $fpCount + $fn, 1);
$accuracy = round(100 * ($tp + $tn) / $n, 2);
$precision = ($tp + $fpCount) > 0 ? round($tp / ($tp + $fpCount), 4) : 0.0;
$recall = ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 4) : 0.0;
$f1 = ($precision + $recall) > 0 ? round(2 * $precision * $recall / ($precision + $recall), 4) : 0.0;
$avg = (int) round($sumLatency / max($totalRuns, 1));
$p95 = $latencies[(int) ceil(0.95 * count($latencies)) - 1];

$summary = [
    'n' => $totalRuns,
    'tp' => $tp,
    'tn' => $tn,
    'fp' => $fpCount,
    'fn' => $fn,
    'accuracy_pct' => $accuracy,
    'precision' => $precision,
    'recall' => $recall,
    'f1' => $f1,
    'avg_ms' => $avg,
    'p95_ms' => $p95,
    'providers' => $providers,
    'mock_api_mode' => MockApiHelper::isEnabled(),
    'mode' => $mode,
    'dataset' => $datasetPath,
    'misses' => $misses,
];
file_put_contents($outJson, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nDone. Wrote {$outCsv}\n";
echo "Summary: {$outJson}\n";
echo "Correct={$correct}/{$totalRuns}\n";
echo "Confusion: TP={$tp} TN={$tn} FP={$fpCount} FN={$fn}\n";
echo "Accuracy={$accuracy}% Prec={$precision} Rec={$recall} F1={$f1}\n";
echo "Avg={$avg}ms P95={$p95}ms providers=" . json_encode($providers, JSON_UNESCAPED_UNICODE) . "\n";
echo 'MISS count=' . count($misses) . "\n";
