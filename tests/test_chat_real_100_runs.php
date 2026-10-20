<?php
/**
 * Chat Moderation — LIVE LLM evaluation on chat_moderation_dataset_100.csv
 * Forces MOCK_API_MODE=false. Writes tests/chat_real_results.csv (keeps mock CSV intact).
 *
 * Usage:
 *   C:\xampp\php\php.exe tests/test_chat_real_100_runs.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('MOCK_API_MODE=false');
$_ENV['MOCK_API_MODE'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/GroqService.php';
require_once $root . '/includes/OpenRouterService.php';
require_once $root . '/includes/ChatModerationService.php';

$datasetPath = __DIR__ . '/chat_moderation_dataset_100.csv';
$csvPath = __DIR__ . '/chat_real_results.csv';
$jsonPath = __DIR__ . '/chat_real_summary.json';

if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    exit(1);
}

if (MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK still on after force-off.\n");
    exit(1);
}

$hasLlm = GroqService::isConfigured() || OpenRouterService::isConfigured()
    || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
if (!$hasLlm) {
    fwrite(STDERR, "No LLM API key configured (Groq/OpenRouter/Gemini).\n");
    exit(1);
}

/**
 * @return list<array{no:int,group:string,text:string,gt:int}>
 */
function loadChatDatasetLive(string $path): array
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

$plan = loadChatDatasetLive($datasetPath);
$totalRuns = count($plan);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, 'DB failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$moderation = new ChatModerationService($conn);
$blockEnabled = defined('CHAT_MODERATION_BLOCK') ? (bool) CHAT_MODERATION_BLOCK : true;

$regexMethod = (new ReflectionClass($moderation))->getMethod('scanTextRegex');
$regexMethod->setAccessible(true);

$fp = fopen($csvPath, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, [
    'No.', 'Group', 'GT Risky (1/0)', 'Regex Score', 'AI Provider Used', 'AI Score',
    'Final Score', 'Verdict', 'Blocked?', 'Latency ms', 'Note',
]);

echo "=== Chat LIVE evaluation ===\n";
echo "Rows={$totalRuns} MOCK=false\n";
echo "GROQ=" . (GroqService::isConfigured() ? 'yes' : 'no')
    . " OR=" . (OpenRouterService::isConfigured() ? 'yes' : 'no')
    . " GEMINI=" . ((defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') ? 'yes' : 'no') . "\n";
echo "Output: {$csvPath}\n\n";

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

    // gentle rate-limit when LLM was invoked
    if ($aiProvider !== 'none') {
        usleep(200_000);
    }
}

fclose($fp);
mysqli_close($conn);

sort($latencies);
$n = max($tp + $tn + $fpCount + $fn, 1);
$accuracy = round(100 * ($tp + $tn) / $n, 2);
$precision = ($tp + $fpCount) > 0 ? round($tp / ($tp + $fpCount), 4) : 0;
$recall = ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 4) : 0;
$f1 = ($precision + $recall) > 0 ? round(2 * $precision * $recall / ($precision + $recall), 4) : 0;
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
    'mock_api_mode' => false,
    'misses' => $misses,
    'compare_to_mock' => [
        'mock_accuracy_pct' => 97,
        'mock_tp_tn_fp_fn' => [48, 49, 1, 2],
        'delta_accuracy_pp' => round($accuracy - 97, 2),
    ],
];
file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nDone. Wrote {$csvPath}\n";
echo "Correct={$correct}/{$totalRuns}\n";
echo "Confusion: TP={$tp} TN={$tn} FP={$fpCount} FN={$fn}\n";
echo "Accuracy={$accuracy}% Prec={$precision} Rec={$recall} F1={$f1}\n";
echo "Avg={$avg}ms P95={$p95}ms providers=" . json_encode($providers) . "\n";
echo "MISS count=" . count($misses) . " | vs Mock Acc 97% → delta " . $summary['compare_to_mock']['delta_accuracy_pp'] . " pp\n";
