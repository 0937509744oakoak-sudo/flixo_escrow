<?php
/**
 * Run all AI module load tests (100 each) and copy CSVs to docs/appendix_ch4/
 * Usage: C:\xampp\php\php.exe tests/test_all_ai_modules.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$php = PHP_BINARY ?: 'php';
$dir = __DIR__;
$root = dirname(__DIR__);
$scripts = [
    'test_ekyc_100_runs.php',
    'test_chat_100_runs.php',
    'test_image_fraud_100_runs.php',
    'test_dispute_ai_100_runs.php',
    'test_sumsub_100_runs.php',
];

echo "=== FLIXO All-AI Load Tests ===\n";
echo "PHP=$php\n";
foreach ($scripts as $script) {
    $path = $dir . DIRECTORY_SEPARATOR . $script;
    if (!is_file($path)) {
        echo "SKIP missing $script\n";
        continue;
    }
    echo "\n>>> Running $script ...\n";
    $code = 0;
    passthru(escapeshellarg($php) . ' ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        fwrite(STDERR, "FAILED $script exit=$code\n");
        exit($code);
    }
}

$out = $root . '/docs/appendix_ch4';
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
foreach (glob($dir . '/*_results.csv') as $csv) {
    $dest = $out . '/' . basename($csv);
    copy($csv, $dest);
    echo "Copied " . basename($csv) . " → docs/appendix_ch4/\n";
}
echo "\nAll AI module tests finished.\n";
