<?php
/**
 * 3-way ablation benchmark for cascaded chat moderation.
 *
 * Systems:
 *   1) regex_only     — Tier-1 only
 *   2) cascade        — legacy escalate (score>=min OR digit-run); soft_trigger=OFF
 *   3) cascade_soft   — cascade + soft escalation cues (proposed)
 *
 * Usage:
 *   php scripts/run_chat_benchmark_ablation.php --mode=mock
 *   php scripts/run_chat_benchmark_ablation.php --mode=live
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

$datasetPath = ablateResolve($root, (string) $args['dataset']);
$outDir = ablateResolve($root, (string) $args['out-dir']);
if (!is_file($datasetPath)) {
    fwrite(STDERR, "Dataset not found: {$datasetPath}\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create out-dir\n");
    exit(1);
}
if ($mode === 'live' && MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK_API_MODE still enabled\n");
    exit(1);
}
if ($mode === 'live') {
    $hasLlm = GroqService::isConfigured() || OpenRouterService::isConfigured()
        || (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '');
    if (!$hasLlm) {
        fwrite(STDERR, "No LLM API key for --mode=live\n");
        exit(1);
    }
}

$raw = json_decode((string) file_get_contents($datasetPath), true);
$messages = is_array($raw)
    ? (array_is_list($raw) ? $raw : (is_array($raw['messages'] ?? null) ? $raw['messages'] : []))
    : [];
if ($messages === []) {
    fwrite(STDERR, "Dataset has no messages\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, defined('DB_PORT') ? (int) DB_PORT : 3306);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
    $service = new ChatModerationService($conn);
} else {
    fwrite(STDERR, "WARNING: DB unavailable — offline scan-only.\n");
    $service = (new ReflectionClass(ChatModerationService::class))->newInstanceWithoutConstructor();
    $conn = null;
}

$n = count($messages);
$systems = ['regex_only', 'cascade', 'cascade_soft'];
echo "=== Chat Ablation Benchmark ===\n";
echo "Mode={$mode} N={$n} systems=" . implode(',', $systems) . "\n\n";

$csvPath = $outDir . DIRECTORY_SEPARATOR . "results_ablation_{$mode}.csv";
$jsonlPath = $outDir . DIRECTORY_SEPARATOR . "results_ablation_{$mode}.jsonl";
$summaryPath = $outDir . DIRECTORY_SEPARATOR . "summary_ablation_{$mode}.json";
$texPath = $outDir . DIRECTORY_SEPARATOR . "paper_tables_ablation_{$mode}.tex";

$csv = fopen($csvPath, 'w');
$jsonl = fopen($jsonlPath, 'w');
if ($csv === false || $jsonl === false) {
    fwrite(STDERR, "Cannot open outputs\n");
    exit(1);
}
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, [
    'message_id', 'ground_truth', 'text_preview',
    'regex_verdict', 'regex_score', 'regex_ms',
    'cascade_verdict', 'cascade_score', 'cascade_tier', 'cascade_provider', 'cascade_ms', 'cascade_cost',
    'soft_verdict', 'soft_score', 'soft_tier', 'soft_provider', 'soft_cues', 'soft_ms', 'soft_cost',
]);

$gold = [];
$preds = ['regex_only' => [], 'cascade' => [], 'cascade_soft' => []];
$latencies = ['regex_only' => [], 'cascade' => [], 'cascade_soft' => []];
$costs = ['regex_only' => [], 'cascade' => [], 'cascade_soft' => []];
$tierCounts = ['cascade' => [], 'cascade_soft' => []];
$softCueHist = [];
$llmEveryCost = [];

foreach ($messages as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (string) ($row['id'] ?? ('msg_' . ($idx + 1)));
    $text = (string) ($row['text'] ?? '');
    $gtLabel = strtoupper(trim((string) ($row['ground_truth'] ?? 'ALLOW')));
    $gtRisky = $gtLabel === 'BLOCK' ? 1 : 0;
    $gold[] = $gtRisky;

    // 1) Regex only
    $t0 = hrtime(true);
    $regexResult = ablateSafeScan(static fn() => $service->scanTextRegexOnly($text));
    $regexMs = (hrtime(true) - $t0) / 1e6;
    $regexVerdict = (string) ($regexResult['verdict'] ?? 'pass');
    $preds['regex_only'][] = ablateRisky($regexVerdict);
    $latencies['regex_only'][] = $regexMs;
    $costs['regex_only'][] = 0.0;

    // 2) Cascade legacy (soft OFF)
    $t1 = hrtime(true);
    $cascadeResult = ablateSafeScan(static fn() => $service->scanText($text, ['soft_trigger' => false]));
    $cascadeMs = (hrtime(true) - $t1) / 1e6;
    $cascadeVerdict = (string) ($cascadeResult['verdict'] ?? 'pass');
    $preds['cascade'][] = ablateRisky($cascadeVerdict);
    $latencies['cascade'][] = $cascadeMs;
    $cTel = is_array($cascadeResult['telemetry'] ?? null) ? $cascadeResult['telemetry'] : [];
    $cTier = (string) ($cTel['tier_reached'] ?? 'TIER_1_REGEX');
    $cProv = (string) ($cTel['provider_used'] ?? 'none');
    $cCost = (float) ($cTel['estimated_cost_usd'] ?? 0.0);
    $costs['cascade'][] = $cCost;
    $tierCounts['cascade'][$cTier] = ($tierCounts['cascade'][$cTier] ?? 0) + 1;

    // 3) Cascade + soft trigger (proposed)
    $t2 = hrtime(true);
    $softResult = ablateSafeScan(static fn() => $service->scanText($text, ['soft_trigger' => true]));
    $softMs = (hrtime(true) - $t2) / 1e6;
    $softVerdict = (string) ($softResult['verdict'] ?? 'pass');
    $preds['cascade_soft'][] = ablateRisky($softVerdict);
    $latencies['cascade_soft'][] = $softMs;
    $sTel = is_array($softResult['telemetry'] ?? null) ? $softResult['telemetry'] : [];
    $sTier = (string) ($sTel['tier_reached'] ?? 'TIER_1_REGEX');
    $sProv = (string) ($sTel['provider_used'] ?? 'none');
    $sCost = (float) ($sTel['estimated_cost_usd'] ?? 0.0);
    $costs['cascade_soft'][] = $sCost;
    $tierCounts['cascade_soft'][$sTier] = ($tierCounts['cascade_soft'][$sTier] ?? 0) + 1;
    $softCues = $sTel['soft_cues'] ?? ($softResult['raw']['soft_cues'] ?? []);
    if (!is_array($softCues)) {
        $softCues = [];
    }
    foreach ($softCues as $cue) {
        $softCueHist[(string) $cue] = ($softCueHist[(string) $cue] ?? 0) + 1;
    }

    $tokens = ChatModerationTelemetry::estimateTokens('moderate:' . $text);
    $llmEveryCost[] = ChatModerationTelemetry::estimateCostUsd('groq', $tokens);

    $record = [
        'message_id' => $id,
        'ground_truth' => $gtRisky ? 'BLOCK' : 'ALLOW',
        'text_preview' => mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 160),
        'regex' => [
            'verdict' => ablateLabel($regexVerdict),
            'score' => round((float) ($regexResult['risk_score'] ?? 0), 2),
            'latency_ms' => round($regexMs, 3),
        ],
        'cascade' => [
            'verdict' => ablateLabel($cascadeVerdict),
            'score' => round((float) ($cascadeResult['risk_score'] ?? 0), 2),
            'tier' => $cTier,
            'provider' => $cProv,
            'latency_ms' => round($cascadeMs, 3),
            'cost_usd' => round($cCost, 8),
        ],
        'cascade_soft' => [
            'verdict' => ablateLabel($softVerdict),
            'score' => round((float) ($softResult['risk_score'] ?? 0), 2),
            'tier' => $sTier,
            'provider' => $sProv,
            'soft_cues' => $softCues,
            'latency_ms' => round($softMs, 3),
            'cost_usd' => round($sCost, 8),
        ],
    ];
    fwrite($jsonl, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fputcsv($csv, [
        $id, $record['ground_truth'], $record['text_preview'],
        $record['regex']['verdict'], $record['regex']['score'], $record['regex']['latency_ms'],
        $record['cascade']['verdict'], $record['cascade']['score'], $cTier, $cProv, $record['cascade']['latency_ms'], $record['cascade']['cost_usd'],
        $record['cascade_soft']['verdict'], $record['cascade_soft']['score'], $sTier, $sProv,
        implode('|', $softCues), $record['cascade_soft']['latency_ms'], $record['cascade_soft']['cost_usd'],
    ]);

    if (($idx + 1) % 25 === 0 || ($idx + 1) === $n) {
        echo sprintf(
            "  [%3d/%d] soft_tier=%s cues=%s\n",
            $idx + 1,
            $n,
            $sTier,
            $softCues !== [] ? implode(',', $softCues) : '-'
        );
    }

    // Rate-limit only when a live LLM call happened
    if ($mode === 'live') {
        $called = false;
        foreach ([$cProv, $sProv] as $p) {
            if ($p !== 'none' && $p !== 'heuristic' && $p !== 'off') {
                $called = true;
                break;
            }
        }
        if ($called) {
            usleep(120_000);
        }
    }
}
fclose($csv);
fclose($jsonl);

$metrics = [];
foreach ($systems as $sys) {
    $m = ablateMetrics($gold, $preds[$sys]);
    $m['cohen_kappa'] = ablateKappa($gold, $preds[$sys]);
    $m['latency'] = ablateLatency($latencies[$sys]);
    $m['estimated_cost_usd_total'] = round(array_sum($costs[$sys]), 8);
    if (isset($tierCounts[$sys])) {
        $t1 = (int) ($tierCounts[$sys]['TIER_1_REGEX'] ?? 0);
        $t2 = (int) ($tierCounts[$sys]['TIER_2_LLM'] ?? 0);
        $t3 = (int) ($tierCounts[$sys]['TIER_3_FALLBACK'] ?? 0);
        $m['tier_counts'] = $tierCounts[$sys];
        $m['tier1_only_n'] = $t1;
        $m['tier1_only_pct'] = $n > 0 ? round(100.0 * $t1 / $n, 2) : 0.0;
        $m['escalated_n'] = $t2 + $t3;
        $m['api_call_saving_pct_vs_llm_every'] = $n > 0
            ? round(100.0 * (1.0 - (($t2 + $t3) / $n)), 2) : 0.0;
    }
    $sumEvery = array_sum($llmEveryCost);
    $m['cost_saving_pct_vs_llm_every'] = $sumEvery > 0
        ? round(100.0 * (1.0 - (array_sum($costs[$sys]) / $sumEvery)), 2)
        : 100.0;
    $metrics[$sys] = $m;
}

$comparisons = [
    'mcnemar_regex_vs_cascade' => ablateMcnemar($gold, $preds['regex_only'], $preds['cascade']),
    'mcnemar_regex_vs_cascade_soft' => ablateMcnemar($gold, $preds['regex_only'], $preds['cascade_soft']),
    'mcnemar_cascade_vs_cascade_soft' => ablateMcnemar($gold, $preds['cascade'], $preds['cascade_soft']),
    'llm_every_counterfactual_cost_usd' => round(array_sum($llmEveryCost), 8),
];

// Pairwise error deltas for paper narrative
$fnRescue = ['cascade_over_regex' => 0, 'soft_over_regex' => 0, 'soft_over_cascade' => 0];
$fpNew = ['cascade_over_regex' => 0, 'soft_over_regex' => 0, 'soft_over_cascade' => 0];
for ($i = 0; $i < $n; $i++) {
    $g = $gold[$i];
    $r = $preds['regex_only'][$i];
    $c = $preds['cascade'][$i];
    $s = $preds['cascade_soft'][$i];
    if ($g === 1) {
        if ($r === 0 && $c === 1) {
            $fnRescue['cascade_over_regex']++;
        }
        if ($r === 0 && $s === 1) {
            $fnRescue['soft_over_regex']++;
        }
        if ($c === 0 && $s === 1) {
            $fnRescue['soft_over_cascade']++;
        }
    } else {
        if ($r === 0 && $c === 1) {
            $fpNew['cascade_over_regex']++;
        }
        if ($r === 0 && $s === 1) {
            $fpNew['soft_over_regex']++;
        }
        if ($c === 0 && $s === 1) {
            $fpNew['soft_over_cascade']++;
        }
    }
}

$summary = [
    'mode' => $mode,
    'mock_api_mode' => MockApiHelper::isEnabled(),
    'dataset' => $datasetPath,
    'n' => $n,
    'n_block_gold' => array_sum($gold),
    'n_allow_gold' => $n - array_sum($gold),
    'systems' => $metrics,
    'comparisons' => $comparisons,
    'fn_rescued' => $fnRescue,
    'fp_introduced' => $fpNew,
    'soft_cue_histogram' => $softCueHist,
    'generated_at' => gmdate('c'),
    'note' => '3-way ablation: regex_only vs cascade(soft=OFF) vs cascade_soft(soft=ON). No fabricated numbers.',
];

file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents($texPath, ablateLatex($summary));

if ($conn instanceof mysqli) {
    mysqli_close($conn);
}

echo "\n========== ABLATION RESULTS ==========\n";
foreach ($systems as $sys) {
    $m = $metrics[$sys];
    echo sprintf(
        "%-14s Acc=%.1f%% P=%.3f R=%.3f F1=%.3f κ=%.3f | TP=%d FP=%d FN=%d | mean=%.0fms | cost=$%.4f%s\n",
        $sys,
        $m['accuracy'] * 100,
        $m['precision'],
        $m['recall'],
        $m['f1'],
        $m['cohen_kappa'],
        $m['tp'],
        $m['fp'],
        $m['fn'],
        $m['latency']['mean'],
        $m['estimated_cost_usd_total'],
        isset($m['tier1_only_pct']) ? sprintf(' | Tier1=%.1f%%', $m['tier1_only_pct']) : ''
    );
}
echo "McNemar regex vs soft: p≈" . $comparisons['mcnemar_regex_vs_cascade_soft']['p_approx']
    . ' sig=' . ($comparisons['mcnemar_regex_vs_cascade_soft']['significant_0_05'] ? 'yes' : 'no') . "\n";
echo "McNemar cascade vs soft: p≈" . $comparisons['mcnemar_cascade_vs_cascade_soft']['p_approx']
    . ' sig=' . ($comparisons['mcnemar_cascade_vs_cascade_soft']['significant_0_05'] ? 'yes' : 'no') . "\n";
echo "FN rescued soft>cascade: {$fnRescue['soft_over_cascade']} | FP new soft>cascade: {$fpNew['soft_over_cascade']}\n";
echo "Soft cue hist: " . json_encode($softCueHist, JSON_UNESCAPED_UNICODE) . "\n";
echo "Wrote:\n  {$csvPath}\n  {$jsonlPath}\n  {$summaryPath}\n  {$texPath}\n";

// ---- helpers ----

function ablateResolve(string $root, string $path): string
{
    if ($path !== '' && preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $path)) {
        return $path;
    }
    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function ablateSafeScan(callable $fn): array
{
    try {
        $r = $fn();
        return is_array($r) ? $r : ['verdict' => 'pass', 'risk_score' => 0];
    } catch (Throwable $e) {
        return ['verdict' => 'pass', 'risk_score' => 0, 'error' => $e->getMessage()];
    }
}

function ablateRisky(string $verdict): int
{
    return in_array(strtolower(trim($verdict)), ['flag', 'reject', 'block'], true) ? 1 : 0;
}

function ablateLabel(string $verdict): string
{
    return ablateRisky($verdict) === 1 ? 'BLOCK' : 'ALLOW';
}

/** @param list<int> $gold @param list<int> $pred */
function ablateMetrics(array $gold, array $pred): array
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
        'tp' => $tp, 'tn' => $tn, 'fp' => $fp, 'fn' => $fn,
        'accuracy' => round($acc, 6),
        'precision' => round($precision, 6),
        'recall' => round($recall, 6),
        'f1' => round($f1, 6),
    ];
}

/** @param list<int> $gold @param list<int> $pred */
function ablateKappa(array $gold, array $pred): float
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
    $pe = ($g1 / $n) * ($p1 / $n) + (1 - $g1 / $n) * (1 - $p1 / $n);
    if (abs(1 - $pe) < 1e-12) {
        return 1.0;
    }
    return round(($po - $pe) / (1 - $pe), 6);
}

/** @param list<int> $gold @param list<int> $a @param list<int> $b */
function ablateMcnemar(array $gold, array $a, array $b): array
{
    $x = $y = 0;
    $n = min(count($gold), count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $g = (int) $gold[$i];
        $aOk = ((int) $a[$i] === $g);
        $bOk = ((int) $b[$i] === $g);
        if ($bOk && !$aOk) {
            $x++;
        } elseif ($aOk && !$bOk) {
            $y++;
        }
    }
    $chi2Corr = 0.0;
    if (($x + $y) > 0 && abs($x - $y) > 1) {
        $chi2Corr = ((abs($x - $y) - 1) ** 2) / ($x + $y);
    }
    $p = $chi2Corr <= 0 ? 1.0 : ablateErfc(sqrt($chi2Corr / 2.0));
    return [
        'b' => $x,
        'c' => $y,
        'chi2_continuity_corrected' => round($chi2Corr, 6),
        'p_approx' => round($p, 8),
        'significant_0_05' => $p < 0.05,
        'note' => 'b=systemB correct when A wrong; c=opposite',
    ];
}

function ablateErfc(float $x): float
{
    $sign = $x < 0 ? -1.0 : 1.0;
    $x = abs($x);
    $t = 1.0 / (1.0 + 0.5 * $x);
    $tau = $t * exp(-$x * $x - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418
        + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587
        + $t * (-0.82215223 + $t * 0.17087277)))))))));
    $erfc = $sign >= 0 ? $tau : (2.0 - $tau);
    return max(0.0, min(2.0, $erfc));
}

/** @param list<float> $xs */
function ablateLatency(array $xs): array
{
    $n = count($xs);
    if ($n === 0) {
        return ['n' => 0, 'mean' => 0.0, 'p50' => 0.0, 'p95' => 0.0];
    }
    sort($xs);
    return [
        'n' => $n,
        'mean' => round(array_sum($xs) / $n, 3),
        'p50' => round($xs[(int) floor(0.50 * ($n - 1))], 3),
        'p95' => round($xs[(int) ceil(0.95 * $n) - 1], 3),
    ];
}

function ablateLatex(array $summary): string
{
    $sys = $summary['systems'];
    $cmp = $summary['comparisons'];
    $n = (int) $summary['n'];
    $fmt = static fn(float $x, int $d = 3): string => number_format($x, $d);
    $pct = static fn(float $x): string => number_format($x * 100, 1);

    $rows = '';
    foreach (['regex_only' => 'Regex-only', 'cascade' => 'Cascade (no soft)', 'cascade_soft' => 'Cascade+Soft (proposed)'] as $k => $label) {
        $m = $sys[$k];
        $tier = isset($m['tier1_only_pct']) ? number_format((float) $m['tier1_only_pct'], 1) . '\%' : '---';
        $rows .= sprintf(
            "%s & %s\\%% & %s & %s & %s & %s & %s & %s & %d/%d \\\\\n",
            $label,
            $pct((float) $m['accuracy']),
            $fmt((float) $m['precision']),
            $fmt((float) $m['recall']),
            $fmt((float) $m['f1']),
            $fmt((float) $m['cohen_kappa'], 2),
            $tier,
            number_format((float) $m['latency']['mean'], 1),
            (int) $m['fp'],
            (int) $m['fn']
        );
    }

    $mSoft = $cmp['mcnemar_regex_vs_cascade_soft'];
    $mGap = $cmp['mcnemar_cascade_vs_cascade_soft'];
    $rescue = $summary['fn_rescued'];

    return <<<TEX
% Auto-generated ablation tables (N={$n})
\\begin{tabular}{lccccccrr}
\\toprule
System & Acc & P & R & F1 & \$\\kappa\$ & Tier1 & Mean ms & FP/FN \\\\
\\midrule
{$rows}\\bottomrule
\\end{tabular}

% McNemar: regex vs cascade+soft: b={$mSoft['b']}, c={$mSoft['c']}, p={$mSoft['p_approx']}
% McNemar: cascade vs cascade+soft: b={$mGap['b']}, c={$mGap['c']}, p={$mGap['p_approx']}
% FN rescued soft over cascade: {$rescue['soft_over_cascade']}
TEX;
}
