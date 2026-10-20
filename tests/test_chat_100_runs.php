<?php
/**
 * Chat Moderation evaluation — reads tests/chat_moderation_dataset_100.csv
 * Runs each Text through ChatModerationService and exports tests/chat_results.csv
 * Headers match Chapter 4 §4.5.2 template.
 *
 * Usage (from project root):
 *   C:\xampp\php\php.exe tests/test_chat_100_runs.php
 *
 * Tip: set MOCK_API_MODE=true in .env to avoid burning LLM quota.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/ChatModerationService.php';

$datasetPath = __DIR__ . '/chat_moderation_dataset_100.csv';
$csvPath = __DIR__ . '/chat_results.csv';

if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    exit(1);
}

if (!MockApiHelper::isEnabled()) {
    fwrite(STDERR, "WARNING: MOCK_API_MODE is OFF — this may call real Groq/OpenRouter/Gemini APIs.\n");
    fwrite(STDERR, "Set MOCK_API_MODE=true in .env before load testing.\n");
}

/**
 * @return list<array{no:int,group:string,text:string,gt:int}>
 */
function loadChatDataset(string $path): array {
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException("Cannot open dataset: {$path}");
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException('Dataset is empty.');
    }
    // Strip UTF-8 BOM from first header cell
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

    $map = [];
    foreach ($header as $i => $name) {
        $map[trim((string) $name)] = $i;
    }
    foreach (['No.', 'Group', 'Text', 'GT Risky (1/0)'] as $required) {
        if (!array_key_exists($required, $map)) {
            fclose($fh);
            throw new RuntimeException("Missing column '{$required}' in dataset.");
        }
    }

    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if ($r === [null] || $r === false || count(array_filter($r, static fn($v) => $v !== null && $v !== '')) === 0) {
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

    if ($rows === []) {
        throw new RuntimeException('No data rows found in dataset.');
    }
    return $rows;
}

try {
    $plan = loadChatDataset($datasetPath);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$totalRuns = count($plan);

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) {
    fwrite(STDERR, 'Database connection failed: ' . mysqli_connect_error() . "\n");
    exit(1);
}
mysqli_set_charset($conn, 'utf8mb4');

$moderation = new ChatModerationService($conn);

$headers = [
    'No.',
    'Group',
    'GT Risky (1/0)',
    'Regex Score',
    'AI Provider Used',
    'AI Score',
    'Final Score',
    'Verdict',
    'Blocked?',
    'Latency ms',
    'Note',
];

$fp = fopen($csvPath, 'w');
if ($fp === false) {
    fwrite(STDERR, "Cannot write {$csvPath}\n");
    exit(1);
}
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $headers);

$blockEnabled = defined('CHAT_MODERATION_BLOCK') ? (bool) CHAT_MODERATION_BLOCK : true;
$tp = $tn = $fpCount = $fn = 0;
$sumLatency = 0;
$correct = 0;

$regexMethod = (new ReflectionClass($moderation))->getMethod('scanTextRegex');
$regexMethod->setAccessible(true);

echo "=== Chat Moderation dataset evaluation ===\n";
echo 'Dataset: ' . $datasetPath . "\n";
echo 'Rows: ' . $totalRuns . "\n";
echo 'MOCK_API_MODE=' . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo 'Output: ' . $csvPath . "\n\n";

foreach ($plan as $idx => $case) {
    $no = $case['no'] > 0 ? $case['no'] : ($idx + 1);
    $gtRisky = $case['gt'];
    $group = $case['group'];
    $text = $case['text'];

    $t0 = microtime(true);
    try {
        $result = $moderation->scanText($text);
        $note = implode('; ', $result['reasons'] ?? []);
    } catch (Throwable $e) {
        $result = [
            'provider' => 'error',
            'risk_score' => 0,
            'verdict' => 'pass',
            'raw' => [],
        ];
        $note = 'EXCEPTION: ' . $e->getMessage();
    }
    $latencyMs = (int) round((microtime(true) - $t0) * 1000);
    $sumLatency += $latencyMs;

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

    fputcsv($fp, [
        $no,
        $group,
        $gtRisky,
        $regexScore,
        $aiProvider,
        $aiScore,
        $finalScore,
        $verdict,
        $blocked,
        $latencyMs,
        $note,
    ]);

    if (($idx + 1) % 10 === 0 || ($idx + 1) === $totalRuns) {
        echo sprintf(
            "  [%3d/%d] no=%d group=%s gt=%d pred=%d verdict=%s %s latency=%dms ai=%s\n",
            $idx + 1,
            $totalRuns,
            $no,
            $group,
            $gtRisky,
            $predictedRisky,
            $verdict,
            $isCorrect ? 'OK' : 'MISS',
            $latencyMs,
            $aiProvider
        );
    }
}

fclose($fp);
mysqli_close($conn);

$n = $tp + $tn + $fpCount + $fn;
$accuracy = $n > 0 ? round(100 * ($tp + $tn) / $n, 2) : 0;
$avgLatency = $totalRuns > 0 ? (int) round($sumLatency / $totalRuns) : 0;

echo "\nDone. Wrote {$csvPath}\n";
echo "Dataset rows={$totalRuns} | Correct={$correct}/{$totalRuns}\n";
echo "Confusion: TP={$tp} TN={$tn} FP={$fpCount} FN={$fn}\n";
echo "Accuracy≈{$accuracy}% | Avg Latency={$avgLatency} ms\n";
