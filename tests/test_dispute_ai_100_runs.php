<?php
/**
 * Dispute AI Assistant — 100 runs → dispute_ai_results.csv
 * Gold actions: REFUND 25 / PAYOUT 25 / SPLIT 25 / NEEDS_REVIEW 25
 * Usage: C:\xampp\php\php.exe tests/test_dispute_ai_100_runs.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/DisputeAssistantService.php';

$csvPath = __DIR__ . '/dispute_ai_results.csv';
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if (!$conn) { fwrite(STDERR, mysqli_connect_error() . "\n"); exit(1); }
mysqli_set_charset($conn, 'utf8mb4');

$svc = new DisputeAssistantService($conn);
$actions = ['REFUND_BUYER', 'PAYOUT_SELLER', 'SPLIT', 'NEEDS_REVIEW'];
$plan = [];
foreach ($actions as $a) {
    for ($i = 0; $i < 25; $i++) {
        $plan[] = $a;
    }
}
shuffle($plan);

$headers = ['No.', 'Dispute ID', 'Admin Gold Action', 'AI Action', 'Provider', 'Confidence', 'Agree (1/0)', 'Latency ms', 'Note'];
$fp = fopen($csvPath, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, $headers);

$agree = 0;
$sum = 0;
echo "=== Dispute AI 100-run ===\nMOCK=" . (MockApiHelper::isEnabled() ? 'on' : 'off') . "\n";

foreach ($plan as $idx => $gold) {
    $no = $idx + 1;
    $amount = 1500.0;
    $context = [
        'order' => [
            'order_id' => 900000 + $no,
            'item_name' => 'LoadTest Item',
            'amount' => $amount,
            'order_status' => 'disputed',
            'buyer_name' => 'buyer_test',
            'seller_name' => 'seller_test',
            'courier' => 'Kerry Express',
            'tracking_number' => $gold === 'PAYOUT_SELLER' ? 'KY' . $no : ($gold === 'REFUND_BUYER' ? '' : 'TR' . $no),
            'tracking_status' => $gold === 'PAYOUT_SELLER' ? 'Delivered' : 'In transit',
            'tracking_status_code' => $gold === 'PAYOUT_SELLER' ? 'DELIVERED' : 'IN_TRANSIT',
        ],
        'dispute' => [
            'reason' => $gold === 'REFUND_BUYER' ? 'ไม่ได้รับสินค้า' : ($gold === 'SPLIT' ? 'สินค้าชำรุด ไม่ตรงปก' : 'ข้อพิพาททั่วไป'),
            'description' => 'เคสทดสอบ load test #' . $no,
            'initiator' => 'buyer',
            'status' => 'open',
            'created_at' => date('Y-m-d H:i:s'),
        ],
        'evidences' => $gold === 'SPLIT' ? [['id' => 1]] : [],
        'chat_flags' => [],
        'messages' => [],
    ];

    MockApiHelper::setDisputeAction($gold);
    $t0 = microtime(true);
    $result = $svc->analyzeContextForBenchmark($context, false);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $sum += $ms;

    $aiAction = (string) ($result['recommended_action'] ?? '');
    $ok = $aiAction === $gold ? 1 : 0;
    $agree += $ok;
    $provider = (string) ($result['provider'] ?? '');
    $conf = (int) ($result['confidence'] ?? 0);
    $note = (string) ($result['summary'] ?? '');

    fputcsv($fp, [$no, 'SYN-' . $no, $gold, $aiAction, $provider, $conf, $ok, $ms, $note]);
    if ($no % 10 === 0) echo "  [$no/100] gold=$gold ai=$aiAction agree=$ok ms=$ms\n";
    MockApiHelper::clearScenario();
}
fclose($fp);
mysqli_close($conn);
echo "Done $csvPath | Agreement=" . round(100 * $agree / 100, 2) . "% Avg=" . (int) round($sum / 100) . "ms\n";
