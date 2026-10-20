<?php
/**
 * Build tests/benchmark/benchmark_dataset.json from the labeled Thai chat CSV
 * and annotate conversation phases for the cascaded-moderation paper.
 *
 * Usage:
 *   php tests/build_benchmark_dataset.php
 *   php tests/build_benchmark_dataset.php --src=tests/chat_moderation_dataset_100.csv
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = __DIR__ . '/chat_moderation_dataset_100.csv';
$out = __DIR__ . '/benchmark/benchmark_dataset.json';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--src=(.*)$/', $arg, $m)) {
        $src = $m[1][0] === '/' || preg_match('/^[A-Za-z]:/', $m[1])
            ? $m[1]
            : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $m[1]);
    }
    if (preg_match('/^--out=(.*)$/', $arg, $m)) {
        $out = $m[1][0] === '/' || preg_match('/^[A-Za-z]:/', $m[1])
            ? $m[1]
            : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $m[1]);
    }
}

if (!is_file($src)) {
    fwrite(STDERR, "Source CSV not found: {$src}\n");
    exit(1);
}

$fh = fopen($src, 'r');
if ($fh === false) {
    fwrite(STDERR, "Cannot open {$src}\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fclose($fh);
    fwrite(STDERR, "Empty CSV\n");
    exit(1);
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
$map = [];
foreach ($header as $i => $name) {
    $map[trim((string) $name)] = $i;
}
foreach (['No.', 'Group', 'Text', 'GT Risky (1/0)'] as $req) {
    if (!isset($map[$req])) {
        fclose($fh);
        fwrite(STDERR, "Missing column {$req}\n");
        exit(1);
    }
}

$messages = [];
while (($r = fgetcsv($fh)) !== false) {
    if ($r === [null] || count(array_filter($r, static fn($v) => $v !== null && $v !== '')) === 0) {
        continue;
    }
    $no = (int) ($r[$map['No.']] ?? 0);
    $group = trim((string) ($r[$map['Group']] ?? ''));
    $text = (string) ($r[$map['Text']] ?? '');
    $gtRisky = (int) ($r[$map['GT Risky (1/0)']] ?? 0);
    $gold = $gtRisky === 1 ? 'BLOCK' : 'ALLOW';
    $phase = $gtRisky === 1 ? 'off_platform_circumvention' : 'normal_inquiry';

    $messages[] = [
        'id' => sprintf('msg_%03d', $no > 0 ? $no : count($messages) + 1),
        'no' => $no > 0 ? $no : count($messages) + 1,
        'group' => $group !== '' ? $group : ($gtRisky === 1 ? 'C2' : 'C1'),
        'phase' => $phase,
        'role' => 'peer',
        'text' => $text,
        'gold_label' => $gold,
        'gt_risky' => $gtRisky,
        'notes' => $gtRisky === 1
            ? 'Off-platform payment/contact bait (binary gold=BLOCK)'
            : 'Normal trade inquiry / escrow-compliant talk (binary gold=ALLOW)',
    ];
}
fclose($fh);

// Illustrative multi-turn threads reflecting the empirical finding:
// ~85% of fraud chats look like normal trade until the payment-bait turn.
$conversations = [
    [
        'conversation_id' => 'thread_demo_01',
        'scenario' => 'normal_then_promptpay_bypass',
        'empirical_note' => 'Mirrors observed Thai C2C fraud: early turns look legitimate; risk appears at direct transfer.',
        'turns' => [
            [
                'turn' => 1,
                'phase' => 'normal_inquiry',
                'text' => 'สภาพเครื่องเป็นยังไง มีรอยไหมคะ',
                'gold_label' => 'ALLOW',
            ],
            [
                'turn' => 2,
                'phase' => 'normal_inquiry',
                'text' => 'ราคาเท่านี้ถือว่าโอเคแล้วสำหรับผม',
                'gold_label' => 'ALLOW',
            ],
            [
                'turn' => 3,
                'phase' => 'off_platform_circumvention',
                'text' => 'พร้อมเพย์ 0898765432 โอนแล้วทักมา',
                'gold_label' => 'BLOCK',
            ],
        ],
    ],
    [
        'conversation_id' => 'thread_demo_02',
        'scenario' => 'normal_then_line_bypass',
        'empirical_note' => 'Contact-channel bait after ordinary product Q&A.',
        'turns' => [
            [
                'turn' => 1,
                'phase' => 'normal_inquiry',
                'text' => 'ขอดูรูปมุมอื่นเพิ่มได้มั้ยครับ',
                'gold_label' => 'ALLOW',
            ],
            [
                'turn' => 2,
                'phase' => 'normal_inquiry',
                'text' => 'ส่งด่วนได้ไหมคะ ถ้าสั่งวันนี้',
                'gold_label' => 'ALLOW',
            ],
            [
                'turn' => 3,
                'phase' => 'off_platform_circumvention',
                'text' => 'แอดไลน์แล้วโอนมาเลย id: cashdeal01',
                'gold_label' => 'BLOCK',
            ],
        ],
    ],
];

$nAllow = count(array_filter($messages, static fn($m) => $m['gold_label'] === 'ALLOW'));
$nBlock = count(array_filter($messages, static fn($m) => $m['gold_label'] === 'BLOCK'));

$payload = [
    'schema_version' => '1.0',
    'dataset_name' => 'flixo_thai_c2c_offplatform_chat_benchmark',
    'language' => 'th',
    'task' => 'binary_off_platform_circumvention_detection',
    'label_space' => ['ALLOW', 'BLOCK'],
    'phase_space' => ['normal_inquiry', 'off_platform_circumvention'],
    'description' =>
        'Thai C2C escrow chat benchmark for cascaded hybrid moderation. '
        . 'Labels distinguish normal trade inquiry from off-platform payment/contact circumvention. '
        . 'Empirical motivation: fraud screenshots (N≈100) show most early turns look like normal trade; '
        . 'risky intent concentrates at account/PromptPay/Line sharing turns.',
    'empirical_context' => [
        'fraud_screenshot_pool_n' => 100,
        'observed_normal_looking_preamble_rate_approx' => 0.85,
        'implication' =>
            'Regex-only misses soft/evasive bait (FN risk); LLM-on-every-message is costly; '
            . 'cascaded hybrid escalates only ambiguous mid-score cases.',
    ],
    'source' => [
        'primary_csv' => str_replace('\\', '/', substr($src, strlen($root) + 1)),
        'built_at' => gmdate('c'),
    ],
    'stats' => [
        'n_messages' => count($messages),
        'n_allow' => $nAllow,
        'n_block' => $nBlock,
        'n_demo_conversations' => count($conversations),
    ],
    'messages' => $messages,
    'conversations' => $conversations,
];

$dir = dirname($out);
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}
file_put_contents($out, $json . "\n");

echo "Wrote {$out}\n";
echo "messages=" . count($messages) . " ALLOW={$nAllow} BLOCK={$nBlock}\n";
