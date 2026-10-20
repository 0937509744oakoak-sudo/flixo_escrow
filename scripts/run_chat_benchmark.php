<?php
/**
 * Run chat moderation benchmark on OCR fraud-screenshot dataset.
 *
 * Baseline : Tier-1 Regex only  (ChatModerationService::scanTextRegexOnly)
 * Proposed : Cascaded Hybrid    (ChatModerationService::scanText)
 *
 * Usage:
 *   php scripts/run_chat_benchmark.php
 *   php scripts/run_chat_benchmark.php --mode=mock
 *   php scripts/run_chat_benchmark.php --mode=live
 *   php scripts/run_chat_benchmark.php --dataset=dataset/benchmark_dataset.json --mode=mock
 *
 * Outputs under dataset/benchmark_runs/:
 *   results_{mode}.jsonl | results_{mode}.csv | summary_{mode}.json | paper_tables_{mode}.tex
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = [
    'dataset' => 'dataset/benchmark_dataset.json',
    'mode' => 'mock',
    'out-dir' => 'dataset/benchmark_runs',
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
require_once $root . '/includes/ChatModerationTelemetry.php';

$datasetPath = resolvePathLocal($root, (string) $args['dataset']);
$outDir = resolvePathLocal($root, (string) $args['out-dir']);

if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create out-dir: {$outDir}\n");
    exit(1);
}

if ($mode === 'live' && MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK_API_MODE still enabled after force-off.\n");
    exit(1);
}
if ($mode === 'live') {
    $hasLlm = GroqService::isConfigured() || OpenRouterService::isConfigured()
        || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
    if (!$hasLlm) {
        fwrite(STDERR, "No LLM API key configured for --mode=live\n");
        exit(1);
    }
}

$raw = json_decode((string) file_get_contents($datasetPath), true);
if (!is_array($raw)) {
    fwrite(STDERR, "Invalid JSON dataset\n");
    exit(1);
}
// Support flat array OR {messages:[...]}
$messages = array_is_list($raw) ? $raw : (is_array($raw['messages'] ?? null) ? $raw['messages'] : null);
if (!is_array($messages) || $messages === []) {
    fwrite(STDERR, "Dataset has no messages\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int) DB_PORT : 3306);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $service = new ChatModerationService($conn);
} else {
    fwrite(STDERR, "WARNING: DB unavailable — offline scan-only benchmark.\n");
    $ref = new ReflectionClass(ChatModerationService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $conn = null;
}

$n = count($messages);
echo "=== Chat Benchmark (OCR fraud screenshots) ===\n";
echo "Mode={$mode} MOCK_API_MODE=" . (MockApiHelper::isEnabled() ? 'true' : 'false') . "\n";
echo "Dataset={$datasetPath}\n";
echo "N={$n}\n\n";

$jsonlPath = $outDir . DIRECTORY_SEPARATOR . "results_{$mode}.jsonl";
$csvPath = $outDir . DIRECTORY_SEPARATOR . "results_{$mode}.csv";
$summaryPath = $outDir . DIRECTORY_SEPARATOR . "summary_{$mode}.json";
$texPath = $outDir . DIRECTORY_SEPARATOR . "paper_tables_{$mode}.tex";

$jsonl = fopen($jsonlPath, 'w');
$csv = fopen($csvPath, 'w');
if ($jsonl === false || $csv === false) {
    fwrite(STDERR, "Cannot open output files\n");
    exit(1);
}
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, [
    'message_id', 'ground_truth', 'category', 'source_image',
    'baseline_verdict', 'baseline_score', 'baseline_latency_ms',
    'proposed_verdict', 'proposed_score', 'tier_reached', 'provider_used',
    'regex_score', 'llm_confidence', 'latency_ms', 'estimated_cost_usd',
]);

$gold = [];
$basePred = [];
$propPred = [];
$baseLatency = [];
$propLatency = [];
$propCost = [];
$llmEveryCost = [];
$tierCounts = [];
$providerCounts = [];

foreach ($messages as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (string) ($row['id'] ?? ('msg_' . ($idx + 1)));
    $text = (string) ($row['text'] ?? '');
    $gtLabel = strtoupper(trim((string) ($row['ground_truth'] ?? 'ALLOW')));
    $gtRisky = $gtLabel === 'BLOCK' ? 1 : 0;
    $category = (string) ($row['category'] ?? '');
    $sourceImage = (string) ($row['source_image'] ?? '');
    $gold[] = $gtRisky;

    // --- Baseline: Regex only ---
    $t0 = hrtime(true);
    try {
        $baseResult = $service->scanTextRegexOnly($text);
    } catch (Throwable $e) {
        $baseResult = [
            'verdict' => 'pass',
            'risk_score' => 0,
            'error' => $e->getMessage(),
        ];
    }
    $baseMs = (hrtime(true) - $t0) / 1_000_000;
    $baseLatency[] = $baseMs;
    $baseVerdictInternal = (string) ($baseResult['verdict'] ?? 'pass');
    $baseRisky = verdictToRiskyBit($baseVerdictInternal);
    $basePred[] = $baseRisky;
    $baseVerdict = mapVerdictLabel($baseVerdictInternal);
    $baseScore = (float) ($baseResult['risk_score'] ?? 0);

    // --- Proposed: Cascaded Hybrid ---
    $t1 = hrtime(true);
    try {
        $propResult = $service->scanText($text);
    } catch (Throwable $e) {
        $propResult = [
            'verdict' => 'pass',
            'risk_score' => 0,
            'telemetry' => [],
            'error' => $e->getMessage(),
        ];
    }
    $propMs = (hrtime(true) - $t1) / 1_000_000;
    $propLatency[] = $propMs;
    $propVerdictInternal = (string) ($propResult['verdict'] ?? 'pass');
    $propRisky = verdictToRiskyBit($propVerdictInternal);
    $propPred[] = $propRisky;
    $propVerdict = mapVerdictLabel($propVerdictInternal);

    $tel = is_array($propResult['telemetry'] ?? null) ? $propResult['telemetry'] : [];
    $tier = (string) ($tel['tier_reached'] ?? 'TIER_1_REGEX');
    $provider = (string) ($tel['provider_used'] ?? 'none');
    $cost = (float) ($tel['estimated_cost_usd'] ?? 0.0);
    $regexScore = array_key_exists('regex_score', $tel) ? (float) $tel['regex_score'] : $baseScore;
    $llmConf = array_key_exists('llm_confidence', $tel) && $tel['llm_confidence'] !== null
        ? (float) $tel['llm_confidence'] : null;

    $propCost[] = $cost;
    $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;
    $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;

    $tokens = ChatModerationTelemetry::estimateTokens('moderate:' . $text);
    $llmEveryCost[] = ChatModerationTelemetry::estimateCostUsd(
        ($provider !== 'none' && $provider !== 'heuristic' && $provider !== 'off') ? $provider : 'groq',
        $tokens
    );

    $record = [
        'message_id' => $id,
        'ground_truth' => $gtLabel === 'BLOCK' ? 'BLOCK' : 'ALLOW',
        'category' => $category,
        'source_image' => $sourceImage,
        'baseline_verdict' => $baseVerdict,
        'baseline_score' => round($baseScore, 2),
        'baseline_latency_ms' => round($baseMs, 3),
        'proposed_verdict' => $propVerdict,
        'proposed_score' => round((float) ($propResult['risk_score'] ?? 0), 2),
        'tier_reached' => $tier,
        'provider_used' => $provider,
        'regex_score' => round($regexScore, 2),
        'llm_confidence' => $llmConf !== null ? round($llmConf, 2) : null,
        'latency_ms' => round($propMs, 3),
        'estimated_cost_usd' => round($cost, 8),
    ];
    fwrite($jsonl, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fputcsv($csv, [
        $record['message_id'], $record['ground_truth'], $record['category'], $record['source_image'],
        $record['baseline_verdict'], $record['baseline_score'], $record['baseline_latency_ms'],
        $record['proposed_verdict'], $record['proposed_score'], $record['tier_reached'], $record['provider_used'],
        $record['regex_score'], $record['llm_confidence'] ?? '', $record['latency_ms'], $record['estimated_cost_usd'],
    ]);

    if (($idx + 1) % 25 === 0 || ($idx + 1) === $n) {
        echo sprintf("  [%3d/%d] last_tier=%s provider=%s\n", $idx + 1, $n, $tier, $provider);
    }
    if ($mode === 'live' && $provider !== 'none' && $provider !== 'heuristic' && $provider !== 'off') {
        usleep(150_000);
    }
}
fclose($jsonl);
fclose($csv);

$baseMetrics = binaryMetricsLocal($gold, $basePred);
$propMetrics = binaryMetricsLocal($gold, $propPred);
$kappaBase = cohenKappaLocal($gold, $basePred);
$kappaProp = cohenKappaLocal($gold, $propPred);
$mcnemar = mcnemarLocal($gold, $basePred, $propPred);
$baseLat = latencyStatsLocal($baseLatency);
$propLat = latencyStatsLocal($propLatency);

$sumPropCost = array_sum($propCost);
$sumLlmEvery = array_sum($llmEveryCost);
$tier1 = (int) ($tierCounts['TIER_1_REGEX'] ?? 0);
$tier2 = (int) ($tierCounts['TIER_2_LLM'] ?? 0);
$tier3 = (int) ($tierCounts['TIER_3_FALLBACK'] ?? 0);
$escalated = $tier2 + $tier3;
$apiSavePct = $n > 0 ? round(100.0 * (1.0 - ($escalated / $n)), 2) : 0.0;
$costSavePct = $sumLlmEvery > 0
    ? round(100.0 * (1.0 - ($sumPropCost / $sumLlmEvery)), 2)
    : ($escalated === 0 ? 100.0 : 0.0);

// Mean latency of escalated calls as LLM-every proxy
$escLat = [];
$fh = fopen($jsonlPath, 'r');
if ($fh) {
    while (($line = fgets($fh)) !== false) {
        $o = json_decode($line, true);
        if (!is_array($o)) {
            continue;
        }
        $t = (string) ($o['tier_reached'] ?? '');
        if ($t === 'TIER_2_LLM' || $t === 'TIER_3_FALLBACK') {
            $escLat[] = (float) ($o['latency_ms'] ?? 0);
        }
    }
    fclose($fh);
}
$llmEveryMean = $escLat !== [] ? array_sum($escLat) / count($escLat) : $propLat['mean'];
$latSavePct = $llmEveryMean > 0
    ? round(100.0 * (1.0 - ($propLat['mean'] / $llmEveryMean)), 2)
    : 0.0;

$summary = [
    'mode' => $mode,
    'mock_api_mode' => MockApiHelper::isEnabled(),
    'dataset' => $datasetPath,
    'n' => $n,
    'n_block_gold' => array_sum($gold),
    'n_allow_gold' => $n - array_sum($gold),
    'baseline_regex_only' => array_merge($baseMetrics, [
        'cohen_kappa' => $kappaBase,
        'latency' => $baseLat,
        'estimated_cost_usd_total' => 0.0,
        'definition' => 'Tier-1 Regex only; ALLOW if pass, BLOCK if flag|reject (production thresholds)',
    ]),
    'proposed_cascaded_hybrid' => array_merge($propMetrics, [
        'cohen_kappa' => $kappaProp,
        'latency' => $propLat,
        'estimated_cost_usd_total' => round($sumPropCost, 8),
        'tier_counts' => $tierCounts,
        'provider_counts' => $providerCounts,
        'tier1_only_n' => $tier1,
        'tier1_only_pct' => $n > 0 ? round(100.0 * $tier1 / $n, 2) : 0.0,
        'tier2_n' => $tier2,
        'tier3_n' => $tier3,
        'escalated_n' => $escalated,
    ]),
    'comparisons' => [
        'mcnemar_baseline_vs_proposed' => $mcnemar,
        'api_call_saving_pct_vs_llm_every_message' => $apiSavePct,
        'cost_saving_pct_vs_llm_every_message' => $costSavePct,
        'latency_saving_pct_vs_llm_every_message' => $latSavePct,
        'llm_every_message_mean_latency_proxy_ms' => round($llmEveryMean, 3),
        'llm_only_counterfactual_cost_usd_total' => round($sumLlmEvery, 8),
    ],
    'generated_at' => gmdate('c'),
    'note' => 'All metrics computed from this CLI run against ChatModerationService. No fabricated numbers.',
];

file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($texPath, buildLatexLocal($summary));

if ($conn instanceof mysqli) {
    mysqli_close($conn);
}

echo "\n========== RESULTS (from this run only) ==========\n";
echo sprintf(
    "Baseline Regex-only: Acc=%.2f%% P=%.4f R=%.4f F1=%.4f κ=%.4f | TP=%d TN=%d FP=%d FN=%d | mean=%.1fms p95=%.1fms\n",
    $baseMetrics['accuracy'] * 100,
    $baseMetrics['precision'],
    $baseMetrics['recall'],
    $baseMetrics['f1'],
    $kappaBase,
    $baseMetrics['tp'],
    $baseMetrics['tn'],
    $baseMetrics['fp'],
    $baseMetrics['fn'],
    $baseLat['mean'],
    $baseLat['p95']
);
echo sprintf(
    "Proposed Hybrid:     Acc=%.2f%% P=%.4f R=%.4f F1=%.4f κ=%.4f | TP=%d TN=%d FP=%d FN=%d | mean=%.1fms p95=%.1fms\n",
    $propMetrics['accuracy'] * 100,
    $propMetrics['precision'],
    $propMetrics['recall'],
    $propMetrics['f1'],
    $kappaProp,
    $propMetrics['tp'],
    $propMetrics['tn'],
    $propMetrics['fp'],
    $propMetrics['fn'],
    $propLat['mean'],
    $propLat['p95']
);
echo sprintf(
    "McNemar: b=%d c=%d chi2_corr=%.4f p≈%.6f significant@0.05=%s\n",
    $mcnemar['b'],
    $mcnemar['c'],
    $mcnemar['chi2_continuity_corrected'],
    $mcnemar['p_approx'],
    $mcnemar['significant_0_05'] ? 'yes' : 'no'
);
echo sprintf(
    "Efficiency: Tier1=%.1f%% (%d/%d) | API-call save vs LLM-every=%.2f%% | cost save=%.2f%% | latency save≈%.2f%%\n",
    $n > 0 ? 100.0 * $tier1 / $n : 0,
    $tier1,
    $n,
    $apiSavePct,
    $costSavePct,
    $latSavePct
);
echo sprintf(
    "Cost: hybrid_total=$%.6f | llm_every_counterfactual=$%.6f | providers=%s\n",
    $sumPropCost,
    $sumLlmEvery,
    json_encode($providerCounts, JSON_UNESCAPED_UNICODE)
);
echo "Wrote:\n  {$jsonlPath}\n  {$csvPath}\n  {$summaryPath}\n  {$texPath}\n";

// ---------------- helpers ----------------

function resolvePathLocal(string $root, string $path): string
{
    if ($path !== '' && preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $path)) {
        return $path;
    }
    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function verdictToRiskyBit(string $verdict): int
{
    $v = strtolower(trim($verdict));
    return in_array($v, ['flag', 'reject', 'block'], true) ? 1 : 0;
}

function mapVerdictLabel(string $verdict): string
{
    return verdictToRiskyBit($verdict) === 1 ? 'BLOCK' : 'ALLOW';
}

/**
 * @param list<int> $gold
 * @param list<int> $pred
 * @return array<string, mixed>
 */
function binaryMetricsLocal(array $gold, array $pred): array
{
    $tp = $tn = $fp = $fn = 0;
    $n = min(count($gold), count($pred));
    for ($i = 0; $i < $n; $i++) {
        $g = (int) $gold[$i];
        $p = (int) $pred[$i];
        if ($g === 1 && $p === 1) {
            $tp++;
        } elseif ($g === 0 && $p === 0) {
            $tn++;
        } elseif ($g === 0 && $p === 1) {
            $fp++;
        } else {
            $fn++;
        }
    }
    $acc = $n > 0 ? ($tp + $tn) / $n : 0.0;
    $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
    $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
    $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
    return [
        'tp' => $tp,
        'tn' => $tn,
        'fp' => $fp,
        'fn' => $fn,
        'accuracy' => round($acc, 6),
        'precision' => round($precision, 6),
        'recall' => round($recall, 6),
        'f1' => round($f1, 6),
        'acc_ci95' => wilsonCiLocal($tp + $tn, $n),
    ];
}

/**
 * @return array{0:float,1:float}
 */
function wilsonCiLocal(int $successes, int $n, float $z = 1.96): array
{
    if ($n <= 0) {
        return [0.0, 0.0];
    }
    $phat = $successes / $n;
    $denom = 1 + ($z * $z) / $n;
    $centre = $phat + ($z * $z) / (2 * $n);
    $margin = $z * sqrt(($phat * (1 - $phat) + ($z * $z) / (4 * $n)) / $n);
    return [
        round(max(0.0, ($centre - $margin) / $denom), 6),
        round(min(1.0, ($centre + $margin) / $denom), 6),
    ];
}

/**
 * @param list<int> $gold
 * @param list<int> $pred
 */
function cohenKappaLocal(array $gold, array $pred): float
{
    $n = min(count($gold), count($pred));
    if ($n === 0) {
        return 0.0;
    }
    $po = 0;
    $g1 = $p1 = 0;
    for ($i = 0; $i < $n; $i++) {
        if ((int) $gold[$i] === (int) $pred[$i]) {
            $po++;
        }
        $g1 += (int) $gold[$i];
        $p1 += (int) $pred[$i];
    }
    $po /= $n;
    $gPos = $g1 / $n;
    $pPos = $p1 / $n;
    $pe = ($gPos * $pPos) + ((1 - $gPos) * (1 - $pPos));
    if (abs(1 - $pe) < 1e-12) {
        return 1.0;
    }
    return round(($po - $pe) / (1 - $pe), 6);
}

/**
 * @param list<int> $gold
 * @param list<int> $baseline
 * @param list<int> $proposed
 * @return array<string, mixed>
 */
function mcnemarLocal(array $gold, array $baseline, array $proposed): array
{
    $b = $c = 0;
    $n = min(count($gold), count($baseline), count($proposed));
    for ($i = 0; $i < $n; $i++) {
        $g = (int) $gold[$i];
        $baseOk = ((int) $baseline[$i] === $g);
        $propOk = ((int) $proposed[$i] === $g);
        if ($propOk && !$baseOk) {
            $b++;
        } elseif ($baseOk && !$propOk) {
            $c++;
        }
    }
    $chi2 = ($b + $c) > 0 ? (($b - $c) ** 2) / ($b + $c) : 0.0;
    $chi2Corr = 0.0;
    if (($b + $c) > 0 && abs($b - $c) > 1) {
        $chi2Corr = ((abs($b - $c) - 1) ** 2) / ($b + $c);
    }
    $p = chi2Sf1Local($chi2Corr);
    return [
        'b' => $b,
        'c' => $c,
        'chi2' => round($chi2, 6),
        'chi2_continuity_corrected' => round($chi2Corr, 6),
        'p_approx' => round($p, 8),
        'significant_0_05' => $p < 0.05,
        'note' => 'b=Proposed correct when Baseline wrong; c=opposite; continuity-corrected chi2 df=1',
    ];
}

function chi2Sf1Local(float $x): float
{
    if ($x <= 0) {
        return 1.0;
    }
    return erfcApproxLocal(sqrt($x / 2.0));
}

function erfcApproxLocal(float $x): float
{
    $sign = 1.0;
    if ($x < 0) {
        $sign = -1.0;
        $x = -$x;
    }
    $t = 1.0 / (1.0 + 0.5 * $x);
    $tau = $t * exp(
        -$x * $x
        - 1.26551223
        + $t * (1.00002368
        + $t * (0.37409196
        + $t * (0.09678418
        + $t * (-0.18628806
        + $t * (0.27886807
        + $t * (-1.13520398
        + $t * (1.48851587
        + $t * (-0.82215223
        + $t * 0.17087277))))))))
    );
    $erfc = $sign >= 0 ? $tau : (2.0 - $tau);
    return max(0.0, min(2.0, $erfc));
}

/**
 * @param list<float> $xs
 * @return array<string, float|int>
 */
function latencyStatsLocal(array $xs): array
{
    $n = count($xs);
    if ($n === 0) {
        return ['n' => 0, 'mean' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'min' => 0.0, 'max' => 0.0];
    }
    sort($xs);
    return [
        'n' => $n,
        'mean' => round(array_sum($xs) / $n, 3),
        'p50' => round($xs[(int) floor(0.50 * ($n - 1))], 3),
        'p95' => round($xs[(int) ceil(0.95 * $n) - 1], 3),
        'min' => round($xs[0], 3),
        'max' => round($xs[$n - 1], 3),
    ];
}

/**
 * @param array<string, mixed> $summary
 */
function buildLatexLocal(array $summary): string
{
    $r = $summary['baseline_regex_only'];
    $h = $summary['proposed_cascaded_hybrid'];
    $c = $summary['comparisons'];
    $m = $c['mcnemar_baseline_vs_proposed'];
    $mode = (string) $summary['mode'];
    $n = (int) $summary['n'];

    $fmtPct = static fn(float $x): string => number_format($x * 100, 1);
    $fmtF = static fn(float $x, int $d = 3): string => number_format($x, $d);
    $fmtMs = static fn(float $x): string => number_format($x, 1);

    $pStar = !empty($m['significant_0_05'])
        ? '$p<0.05$'
        : sprintf('$p=%.4f$', (float) $m['p_approx']);

    $rAcc = $fmtPct((float) $r['accuracy']);
    $hAcc = $fmtPct((float) $h['accuracy']);
    $tier1Pct = $fmtF((float) $h['tier1_only_pct'], 1);
    $apiSave = $fmtF((float) $c['api_call_saving_pct_vs_llm_every_message'], 1);

    return <<<TEX
% Auto-generated by scripts/run_chat_benchmark.php
% mode={$mode} | N={$n} | source=dataset/benchmark_dataset.json
% DO NOT edit numbers by hand — regenerate from CLI run.

\\begin{table}[t]
\\centering
\\caption{Off-platform circumvention detection on OCR Thai C2C fraud-chat screenshots (N={$n}). FLAG/REJECT map to BLOCK.}
\\label{tab:ocr-chat-detection}
\\begin{tabular}{lcccccc}
\\toprule
Method & Acc. (\\%) & Prec. & Rec. & F1 & \$\\kappa\$ & 95\\% CI (Acc.) \\\\
\\midrule
Regex-only (Tier~1) & {$rAcc} & {$fmtF((float)$r['precision'])} & {$fmtF((float)$r['recall'])} & {$fmtF((float)$r['f1'])} & {$fmtF((float)$r['cohen_kappa'], 2)} & [{$fmtF((float)$r['acc_ci95'][0], 3)}, {$fmtF((float)$r['acc_ci95'][1], 3)}] \\\\
Cascaded Hybrid (proposed) & {$hAcc} & {$fmtF((float)$h['precision'])} & {$fmtF((float)$h['recall'])} & {$fmtF((float)$h['f1'])} & {$fmtF((float)$h['cohen_kappa'], 2)} & [{$fmtF((float)$h['acc_ci95'][0], 3)}, {$fmtF((float)$h['acc_ci95'][1], 3)}] \\\\
\\bottomrule
\\end{tabular}
\\end{table}

\\begin{table}[t]
\\centering
\\caption{Confusion matrix and McNemar test. \$b\$: hybrid correct when regex wrong; \$c\$: opposite.}
\\label{tab:ocr-chat-mcnemar}
\\begin{tabular}{lcccccc}
\\toprule
Method & TP & TN & FP & FN & McNemar \$(b,c)\$ & \$p\$ \\\\
\\midrule
Regex-only & {$r['tp']} & {$r['tn']} & {$r['fp']} & {$r['fn']} & -- & -- \\\\
Cascaded Hybrid & {$h['tp']} & {$h['tn']} & {$h['fp']} & {$h['fn']} & ({$m['b']}, {$m['c']}) & {$pStar} \\\\
\\bottomrule
\\end{tabular}
\\end{table}

\\begin{table}[t]
\\centering
\\caption{Efficiency of the cascade on the OCR screenshot dataset (mode={$mode}).}
\\label{tab:ocr-chat-efficiency}
\\begin{tabular}{lcccc}
\\toprule
Method & Mean (ms) & P95 (ms) & Est.\\ cost (USD) & Notes \\\\
\\midrule
Regex-only & {$fmtMs((float)$r['latency']['mean'])} & {$fmtMs((float)$r['latency']['p95'])} & 0.000 & Tier~1 only \\\\
Cascaded Hybrid & {$fmtMs((float)$h['latency']['mean'])} & {$fmtMs((float)$h['latency']['p95'])} & {$fmtF((float)$h['estimated_cost_usd_total'], 6)} & Tier1={$tier1Pct}\\%; API-call saving={$apiSave}\\% \\\\
LLM every msg (counterfactual) & {$fmtMs((float)$c['llm_every_message_mean_latency_proxy_ms'])} & -- & {$fmtF((float)$c['llm_only_counterfactual_cost_usd_total'], 6)} & 0\\% saving \\\\
\\bottomrule
\\end{tabular}
\\end{table}

TEX;
}
