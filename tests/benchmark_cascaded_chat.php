<?php
/**
 * Cascaded Hybrid Chat Moderation Benchmark
 * -----------------------------------------
 * Compares:
 *   (A) Baseline  — Tier-1 Regex only
 *   (B) Proposed  — Cascaded Hybrid (Regex → LLM → Fallback)
 *
 * Usage:
 *   php tests/benchmark_cascaded_chat.php --mode=mock
 *   php tests/benchmark_cascaded_chat.php --mode=live
 *   php tests/benchmark_cascaded_chat.php --dataset=tests/benchmark/benchmark_dataset.json --mode=mock
 *
 * Outputs (under tests/benchmark/):
 *   - results_{mode}.jsonl
 *   - summary_{mode}.json
 *   - paper_tables_{mode}.tex
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = [
    'dataset' => __DIR__ . '/benchmark/benchmark_dataset.json',
    'mode' => 'mock',
    'out-dir' => __DIR__ . '/benchmark',
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

$datasetPath = resolvePath($root, (string) $args['dataset']);
$outDir = resolvePath($root, (string) $args['out-dir']);
if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    fwrite(STDERR, "Hint: php tests/build_benchmark_dataset.php\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create out-dir: {$outDir}\n");
    exit(1);
}

if ($mode === 'live' && MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK still enabled in live mode.\n");
    exit(1);
}
if ($mode === 'live') {
    $hasLlm = GroqService::isConfigured() || OpenRouterService::isConfigured()
        || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
    if (!$hasLlm) {
        fwrite(STDERR, "No LLM API key configured for live mode.\n");
        exit(1);
    }
}

$dataset = json_decode((string) file_get_contents($datasetPath), true);
if (!is_array($dataset) || empty($dataset['messages']) || !is_array($dataset['messages'])) {
    fwrite(STDERR, "Invalid dataset JSON (messages[] required)\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? DB_PORT : 3306);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $service = new ChatModerationService($conn);
} else {
    fwrite(STDERR, "WARNING: DB unavailable — offline scan-only benchmark.\n");
    $ref = new ReflectionClass(ChatModerationService::class);
    $service = $ref->newInstanceWithoutConstructor();
    $conn = null;
}

$messages = $dataset['messages'];
$n = count($messages);

echo "=== Cascaded Hybrid Benchmark ===\n";
echo "Mode={$mode} MOCK=" . (MockApiHelper::isEnabled() ? '1' : '0') . "\n";
echo "Dataset={$datasetPath}\n";
echo "N={$n}\n\n";

$jsonlPath = $outDir . '/results_' . $mode . '.jsonl';
$summaryPath = $outDir . '/summary_' . $mode . '.json';
$texPath = $outDir . '/paper_tables_' . $mode . '.tex';
$jsonl = fopen($jsonlPath, 'w');
if ($jsonl === false) {
    fwrite(STDERR, "Cannot write {$jsonlPath}\n");
    exit(1);
}

$regexPred = [];
$regexStrictPred = [];
$hybridPred = [];
$gold = [];
$regexLatency = [];
$hybridLatency = [];
$hybridCost = [];
$llmOnlyCost = [];
$tierCounts = [];
$providerCounts = [];
$phaseRows = [
    'normal_inquiry' => ['gold_block' => 0, 'regex_tp' => 0, 'regex_strict_tp' => 0, 'hybrid_tp' => 0, 'n' => 0],
    'off_platform_circumvention' => ['gold_block' => 0, 'regex_tp' => 0, 'regex_strict_tp' => 0, 'hybrid_tp' => 0, 'n' => 0],
];

foreach ($messages as $idx => $row) {
    $text = (string) ($row['text'] ?? '');
    $id = (string) ($row['id'] ?? ('msg_' . ($idx + 1)));
    $gtRisky = (int) ($row['gt_risky'] ?? (($row['gold_label'] ?? 'ALLOW') === 'BLOCK' ? 1 : 0));
    $phase = (string) ($row['phase'] ?? ($gtRisky === 1 ? 'off_platform_circumvention' : 'normal_inquiry'));
    $gold[] = $gtRisky;

    if (!isset($phaseRows[$phase])) {
        $phaseRows[$phase] = ['gold_block' => 0, 'regex_tp' => 0, 'regex_strict_tp' => 0, 'hybrid_tp' => 0, 'n' => 0];
    }
    $phaseRows[$phase]['n']++;
    if ($gtRisky === 1) {
        $phaseRows[$phase]['gold_block']++;
    }

    // --- Baseline: Regex only ---
    $t0 = hrtime(true);
    try {
        $regexResult = $service->scanTextRegexOnly($text);
    } catch (Throwable $e) {
        $regexResult = [
            'verdict' => 'pass',
            'risk_score' => 0,
            'telemetry' => [],
            'error' => $e->getMessage(),
        ];
    }
    $regexMs = (hrtime(true) - $t0) / 1_000_000;
    $regexLatency[] = $regexMs;
    $regexScoreVal = (float) ($regexResult['risk_score'] ?? 0);
    // Lenient: same production thresholds (FLAG|REJECT => risky) — may match hybrid closely.
    $regexRisky = verdictToRisky((string) ($regexResult['verdict'] ?? 'pass'));
    // Strict paper baseline (Chapter 4): treat as risky only when regex score ≥ 70.
    $regexStrictRisky = $regexScoreVal >= 70.0 ? 1 : 0;
    $regexPred[] = $regexRisky;
    $regexStrictPred[] = $regexStrictRisky;

    // --- Proposed: Cascaded Hybrid ---
    $t1 = hrtime(true);
    try {
        $hybridResult = $service->scanText($text);
    } catch (Throwable $e) {
        $hybridResult = [
            'verdict' => 'pass',
            'risk_score' => 0,
            'telemetry' => [],
            'error' => $e->getMessage(),
        ];
    }
    $hybridMs = (hrtime(true) - $t1) / 1_000_000;
    $hybridLatency[] = $hybridMs;
    $hybridRisky = verdictToRisky((string) ($hybridResult['verdict'] ?? 'pass'));
    $hybridPred[] = $hybridRisky;

    $tel = is_array($hybridResult['telemetry'] ?? null) ? $hybridResult['telemetry'] : [];
    $tier = (string) ($tel['tier_reached'] ?? 'TIER_1_REGEX');
    $provider = (string) ($tel['provider_used'] ?? 'none');
    $cost = (float) ($tel['estimated_cost_usd'] ?? 0);
    $hybridCost[] = $cost;
    $tierCounts[$tier] = ($tierCounts[$tier] ?? 0) + 1;
    $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;

    // Hypothetical LLM-on-every-message cost (research counterfactual).
    $prompt = 'moderate:' . $text;
    $tokens = ChatModerationTelemetry::estimateTokens($prompt);
    $llmOnlyCost[] = ChatModerationTelemetry::estimateCostUsd(
        $provider !== 'none' && $provider !== 'heuristic' ? $provider : 'groq',
        $tokens
    );

    if ($gtRisky === 1 && $regexRisky === 1) {
        $phaseRows[$phase]['regex_tp']++;
    }
    if ($gtRisky === 1 && $regexStrictRisky === 1) {
        $phaseRows[$phase]['regex_strict_tp']++;
    }
    if ($gtRisky === 1 && $hybridRisky === 1) {
        $phaseRows[$phase]['hybrid_tp']++;
    }

    $record = [
        'id' => $id,
        'no' => $row['no'] ?? ($idx + 1),
        'phase' => $phase,
        'gold' => $gtRisky === 1 ? 'BLOCK' : 'ALLOW',
        'gt_risky' => $gtRisky,
        'regex' => [
            'pred_risky' => $regexRisky,
            'pred_risky_strict70' => $regexStrictRisky,
            'verdict' => $regexResult['verdict'] ?? 'pass',
            'score' => $regexScoreVal,
            'latency_ms' => round($regexMs, 3),
        ],
        'hybrid' => [
            'pred_risky' => $hybridRisky,
            'verdict' => $hybridResult['verdict'] ?? 'pass',
            'score' => (float) ($hybridResult['risk_score'] ?? 0),
            'latency_ms' => round($hybridMs, 3),
            'tier_reached' => $tier,
            'provider_used' => $provider,
            'regex_score' => $tel['regex_score'] ?? null,
            'llm_confidence' => $tel['llm_confidence'] ?? null,
            'estimated_cost_usd' => $cost,
            'final_verdict' => $tel['final_verdict'] ?? ChatModerationTelemetry::mapVerdict((string) ($hybridResult['verdict'] ?? 'pass')),
        ],
    ];
    fwrite($jsonl, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    if (($idx + 1) % 10 === 0 || ($idx + 1) === $n) {
        echo sprintf("  [%3d/%d] last_tier=%s provider=%s\n", $idx + 1, $n, $tier, $provider);
    }

    if ($mode === 'live' && $provider !== 'none' && $provider !== 'heuristic') {
        usleep(200_000);
    }
}
fclose($jsonl);

$regexMetrics = binaryMetrics($gold, $regexPred);
$regexStrictMetrics = binaryMetrics($gold, $regexStrictPred);
$hybridMetrics = binaryMetrics($gold, $hybridPred);
$kappaRegex = cohenKappa($gold, $regexPred);
$kappaRegexStrict = cohenKappa($gold, $regexStrictPred);
$kappaHybrid = cohenKappa($gold, $hybridPred);
$mcnemarLenient = mcnemarTest($gold, $regexPred, $hybridPred);
$mcnemar = mcnemarTest($gold, $regexStrictPred, $hybridPred);

$regexLatStats = latencyStats($regexLatency);
$hybridLatStats = latencyStats($hybridLatency);
$sumHybridCost = array_sum($hybridCost);
$sumLlmOnlyCost = array_sum($llmOnlyCost);
$escalated = ($tierCounts['TIER_2_LLM'] ?? 0) + ($tierCounts['TIER_3_FALLBACK'] ?? 0);
$tier1Only = $tierCounts['TIER_1_REGEX'] ?? 0;
$apiCallSavingPct = $n > 0 ? round(100.0 * (1.0 - ($escalated / $n)), 2) : 0.0;
$costSavingPct = $sumLlmOnlyCost > 0
    ? round(100.0 * (1.0 - ($sumHybridCost / $sumLlmOnlyCost)), 2)
    : ($escalated === 0 ? 100.0 : 0.0);

// Counterfactual "LLM every message" latency ≈ mean latency of escalated Tier-2 calls.
$escalatedLatencies = [];
$fh2 = fopen($jsonlPath, 'r');
if ($fh2) {
    while (($line = fgets($fh2)) !== false) {
        $obj = json_decode($line, true);
        if (!is_array($obj)) {
            continue;
        }
        $prov = (string) ($obj['hybrid']['provider_used'] ?? 'none');
        $tier = (string) ($obj['hybrid']['tier_reached'] ?? '');
        if ($tier === 'TIER_2_LLM' || ($prov !== 'none' && $prov !== 'heuristic' && $prov !== 'off')) {
            $escalatedLatencies[] = (float) ($obj['hybrid']['latency_ms'] ?? 0);
        }
    }
    fclose($fh2);
}
$llmEveryMean = $escalatedLatencies !== []
    ? array_sum($escalatedLatencies) / count($escalatedLatencies)
    : $hybridLatStats['mean'];
$latencySavingVsLlmEveryPct = $llmEveryMean > 0
    ? round(100.0 * (1.0 - ($hybridLatStats['mean'] / $llmEveryMean)), 2)
    : 0.0;

$summary = [
    'mode' => $mode,
    'mock_api_mode' => MockApiHelper::isEnabled(),
    'dataset' => $datasetPath,
    'n' => $n,
    'baseline_regex_lenient_flag45' => array_merge($regexMetrics, [
        'cohen_kappa' => $kappaRegex,
        'latency' => $regexLatStats,
        'estimated_cost_usd_total' => 0.0,
        'definition' => 'Risky if verdict in {flag,reject} using production thresholds (flag≥45)',
    ]),
    'baseline_regex' => array_merge($regexStrictMetrics, [
        'cohen_kappa' => $kappaRegexStrict,
        'latency' => $regexLatStats,
        'estimated_cost_usd_total' => 0.0,
        'definition' => 'Paper baseline: risky if regex_score ≥ 70 (Chapter-4 comparable)',
    ]),
    'proposed_hybrid' => array_merge($hybridMetrics, [
        'cohen_kappa' => $kappaHybrid,
        'latency' => $hybridLatStats,
        'estimated_cost_usd_total' => round($sumHybridCost, 8),
        'tier_counts' => $tierCounts,
        'provider_counts' => $providerCounts,
        'escalated_n' => $escalated,
        'tier1_only_n' => $tier1Only,
        'tier1_only_pct' => $n > 0 ? round(100.0 * $tier1Only / $n, 2) : 0.0,
    ]),
    'comparisons' => [
        'mcnemar_regex70_vs_hybrid' => $mcnemar,
        'mcnemar_regex_flag45_vs_hybrid' => $mcnemarLenient,
        'api_call_saving_pct_vs_llm_every_message' => $apiCallSavingPct,
        'cost_saving_pct_vs_llm_every_message' => $costSavingPct,
        'hybrid_mean_latency_ms' => $hybridLatStats['mean'],
        'regex_mean_latency_ms' => $regexLatStats['mean'],
        'llm_every_message_mean_latency_proxy_ms' => round($llmEveryMean, 3),
        'latency_saving_pct_vs_llm_every_message' => $latencySavingVsLlmEveryPct,
        'llm_only_counterfactual_cost_usd_total' => round($sumLlmOnlyCost, 8),
    ],
    'phase_breakdown' => $phaseRows,
    'empirical_context' => $dataset['empirical_context'] ?? null,
    'generated_at' => gmdate('c'),
];

file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($texPath, buildLatexTables($summary));

if ($conn instanceof mysqli) {
    mysqli_close($conn);
}

echo "\n--- Results ---\n";
echo sprintf(
    "Regex@70 Acc=%.2f%% P=%.4f R=%.4f F1=%.4f κ=%.4f mean=%.1fms p95=%.1fms\n",
    $regexStrictMetrics['accuracy'] * 100,
    $regexStrictMetrics['precision'],
    $regexStrictMetrics['recall'],
    $regexStrictMetrics['f1'],
    $kappaRegexStrict,
    $regexLatStats['mean'],
    $regexLatStats['p95']
);
echo sprintf(
    "Regex@45 Acc=%.2f%% P=%.4f R=%.4f F1=%.4f κ=%.4f (lenient / same thresholds as prod)\n",
    $regexMetrics['accuracy'] * 100,
    $regexMetrics['precision'],
    $regexMetrics['recall'],
    $regexMetrics['f1'],
    $kappaRegex
);
echo sprintf(
    "Hybrid  Acc=%.2f%% P=%.4f R=%.4f F1=%.4f κ=%.4f mean=%.1fms p95=%.1fms\n",
    $hybridMetrics['accuracy'] * 100,
    $hybridMetrics['precision'],
    $hybridMetrics['recall'],
    $hybridMetrics['f1'],
    $kappaHybrid,
    $hybridLatStats['mean'],
    $hybridLatStats['p95']
);
echo sprintf(
    "McNemar Regex@70 vs Hybrid: b=%d c=%d chi2=%.4f p≈%.6f significant@0.05=%s\n",
    $mcnemar['b'],
    $mcnemar['c'],
    $mcnemar['chi2_continuity_corrected'],
    $mcnemar['p_approx'],
    $mcnemar['significant_0_05'] ? 'yes' : 'no'
);
echo sprintf(
    "API call saving vs LLM-every=%.2f%% | cost saving=%.2f%% | latency saving≈%.2f%%\n",
    $apiCallSavingPct,
    $costSavingPct,
    $latencySavingVsLlmEveryPct
);
echo "Wrote:\n  {$jsonlPath}\n  {$summaryPath}\n  {$texPath}\n";

// ----------------- helpers -----------------

function resolvePath(string $root, string $path): string
{
    if ($path !== '' && (preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $path) || $path[0] === '/' || $path[0] === '\\')) {
        return $path;
    }
    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function verdictToRisky(string $verdict): int
{
    $v = strtolower(trim($verdict));
    return in_array($v, ['flag', 'reject', 'block'], true) ? 1 : 0;
}

/**
 * @param list<int> $gold
 * @param list<int> $pred
 * @return array{tp:int,tn:int,fp:int,fn:int,accuracy:float,precision:float,recall:float,f1:float,acc_ci95:array{0:float,1:float}}
 */
function binaryMetrics(array $gold, array $pred): array
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
        'acc_ci95' => wilsonCi($tp + $tn, $n),
    ];
}

/**
 * @return array{0:float,1:float}
 */
function wilsonCi(int $successes, int $n, float $z = 1.96): array
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
function cohenKappa(array $gold, array $pred): float
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
 * McNemar on paired correctness of baseline vs proposed vs gold.
 * b = proposed correct & baseline wrong; c = opposite.
 *
 * @param list<int> $gold
 * @param list<int> $baselinePred
 * @param list<int> $proposedPred
 * @return array<string, mixed>
 */
function mcnemarTest(array $gold, array $baselinePred, array $proposedPred): array
{
    $b = $c = 0;
    $n = min(count($gold), count($baselinePred), count($proposedPred));
    for ($i = 0; $i < $n; $i++) {
        $g = (int) $gold[$i];
        $baseOk = ((int) $baselinePred[$i] === $g);
        $propOk = ((int) $proposedPred[$i] === $g);
        if ($propOk && !$baseOk) {
            $b++;
        } elseif ($baseOk && !$propOk) {
            $c++;
        }
    }
    $chi2 = ($b + $c) > 0 ? (($b - $c) ** 2) / ($b + $c) : 0.0;
    // continuity-corrected variant commonly reported in applied papers
    $chi2Corr = ($b + $c) > 0 ? ((abs($b - $c) - 1) ** 2) / ($b + $c) : 0.0;
    if ($b + $c === 0) {
        $chi2Corr = 0.0;
    } elseif (abs($b - $c) <= 1) {
        $chi2Corr = 0.0;
    }
    $p = chi2Sf1($chi2Corr);
    return [
        'b' => $b,
        'c' => $c,
        'chi2' => round($chi2, 6),
        'chi2_continuity_corrected' => round($chi2Corr, 6),
        'p_approx' => round($p, 8),
        'significant_0_05' => $p < 0.05,
        'note' => 'b=Hybrid correct when Regex wrong; c=opposite; p from chi2 df=1 continuity-corrected',
    ];
}

/** Survival function P(X > x) for chi-square df=1. */
function chi2Sf1(float $x): float
{
    if ($x <= 0) {
        return 1.0;
    }
    // df=1 ⇒ P(X>x) = erfc(sqrt(x/2)); PHP has no erfc — use complementary error approx.
    return erfcApprox(sqrt($x / 2.0));
}

/**
 * Abramowitz & Stegun complementary error function approximation.
 */
function erfcApprox(float $x): float
{
    // erfc(-x) = 2 - erfc(x)
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
 * @return array{n:int,mean:float,p50:float,p95:float,min:float,max:float}
 */
function latencyStats(array $xs): array
{
    $n = count($xs);
    if ($n === 0) {
        return ['n' => 0, 'mean' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'min' => 0.0, 'max' => 0.0];
    }
    sort($xs);
    $mean = array_sum($xs) / $n;
    $p50 = $xs[(int) floor(0.50 * ($n - 1))];
    $p95 = $xs[(int) ceil(0.95 * $n) - 1];
    return [
        'n' => $n,
        'mean' => round($mean, 3),
        'p50' => round($p50, 3),
        'p95' => round($p95, 3),
        'min' => round($xs[0], 3),
        'max' => round($xs[$n - 1], 3),
    ];
}

/**
 * @param array<string, mixed> $summary
 */
function buildLatexTables(array $summary): string
{
    $r = $summary['baseline_regex'];
    $h = $summary['proposed_hybrid'];
    $c = $summary['comparisons'];
    $mode = (string) $summary['mode'];
    $n = (int) $summary['n'];

    $fmtPct = static fn(float $x): string => number_format($x * 100, 1);
    $fmtF = static fn(float $x, int $d = 3): string => number_format($x, $d);
    $fmtMs = static fn(float $x): string => number_format($x, 1);

    $m = $c['mcnemar_regex70_vs_hybrid'];
    $pStar = !empty($m['significant_0_05'])
        ? '$p<0.05$'
        : sprintf('$p=%.4f$', (float) $m['p_approx']);

    $rAcc = $fmtPct((float) $r['accuracy']);
    $hAcc = $fmtPct((float) $h['accuracy']);
    $rP = $fmtF((float) $r['precision']);
    $hP = $fmtF((float) $h['precision']);
    $rR = $fmtF((float) $r['recall']);
    $hR = $fmtF((float) $h['recall']);
    $rF1 = $fmtF((float) $r['f1']);
    $hF1 = $fmtF((float) $h['f1']);
    $rK = $fmtF((float) $r['cohen_kappa'], 2);
    $hK = $fmtF((float) $h['cohen_kappa'], 2);
    $rCi0 = $fmtF((float) $r['acc_ci95'][0], 3);
    $rCi1 = $fmtF((float) $r['acc_ci95'][1], 3);
    $hCi0 = $fmtF((float) $h['acc_ci95'][0], 3);
    $hCi1 = $fmtF((float) $h['acc_ci95'][1], 3);

    $mb = (int) $m['b'];
    $mc = (int) $m['c'];

    $rMean = $fmtMs((float) $r['latency']['mean']);
    $rP95 = $fmtMs((float) $r['latency']['p95']);
    $hMean = $fmtMs((float) $h['latency']['mean']);
    $hP95 = $fmtMs((float) $h['latency']['p95']);
    $hCost = $fmtF((float) $h['estimated_cost_usd_total'], 6);
    $apiSave = $fmtF((float) $c['api_call_saving_pct_vs_llm_every_message'], 1);
    $llmMean = $fmtMs((float) $c['llm_every_message_mean_latency_proxy_ms']);
    $llmCost = $fmtF((float) $c['llm_only_counterfactual_cost_usd_total'], 6);

    // nowdoc: do not interpolate $b/$c accidentally
    $tex = <<<TEX
% Auto-generated by tests/benchmark_cascaded_chat.php (mode={$mode}, N={$n})
% Primary baseline = Regex score ≥ 70 (paper-comparable). Hybrid uses production cascade.

\\begin{table}[t]
\\centering
\\caption{Detection performance on Thai C2C off-platform circumvention chat (binary ALLOW/BLOCK).}
\\label{tab:chat-detection-metrics}
\\begin{tabular}{lcccccc}
\\toprule
Method & Acc. (\\%) & Prec. & Rec. & F1 & \$\\kappa\$ & 95\\% CI (Acc.) \\\\
\\midrule
Regex-only (score \$\\geq\$ 70) & {$rAcc} & {$rP} & {$rR} & {$rF1} & {$rK} & [{$rCi0}, {$rCi1}] \\\\
Cascaded Hybrid (proposed) & {$hAcc} & {$hP} & {$hR} & {$hF1} & {$hK} & [{$hCi0}, {$hCi1}] \\\\
\\bottomrule
\\end{tabular}
\\end{table}

\\begin{table}[t]
\\centering
\\caption{Confusion counts and McNemar test (paired correctness vs.\\ gold). \$b\$: hybrid correct when regex wrong; \$c\$: opposite.}
\\label{tab:chat-confusion-mcnemar}
\\begin{tabular}{lcccccc}
\\toprule
Method & TP & TN & FP & FN & McNemar \$(b,c)\$ & \$p\$ \\\\
\\midrule
Regex-only (\$\\geq\$70) & {$r['tp']} & {$r['tn']} & {$r['fp']} & {$r['fn']} & -- & -- \\\\
Cascaded Hybrid & {$h['tp']} & {$h['tn']} & {$h['fp']} & {$h['fn']} & ({$mb}, {$mc}) & {$pStar} \\\\
\\bottomrule
\\end{tabular}
\\end{table}

\\begin{table}[t]
\\centering
\\caption{Latency and estimated API cost. Cascade terminates at Tier~1 for most messages, reducing cloud calls versus LLM-on-every-message.}
\\label{tab:chat-latency-cost}
\\begin{tabular}{lcccc}
\\toprule
Method & Mean (ms) & P95 (ms) & Est.\\ cost (USD) & API-call saving \\\\
\\midrule
Regex-only & {$rMean} & {$rP95} & 0.000 & -- \\\\
Cascaded Hybrid & {$hMean} & {$hP95} & {$hCost} & {$apiSave}\\% \\\\
LLM every msg (counterfactual) & {$llmMean} & -- & {$llmCost} & 0\\% \\\\
\\bottomrule
\\end{tabular}
\\end{table}

TEX;

    return $tex;
}
