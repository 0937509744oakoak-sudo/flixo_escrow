<?php
/**
 * OCR Thai C2C fraud screenshots → anonymized benchmark_dataset.json
 *
 * Usage:
 *   php scripts/extract_fraud_screenshot_dataset.php
 *   php scripts/extract_fraud_screenshot_dataset.php --limit=5
 *   php scripts/extract_fraud_screenshot_dataset.php --reuse-ocr=1
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

// Force live Vision OCR regardless of .env MOCK_API_MODE.
putenv('MOCK_API_MODE=false');
$_ENV['MOCK_API_MODE'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/MockApiHelper.php';
require_once $root . '/includes/ChatModerationService.php';

$args = [
    'src' => $root . '/raw_fraud_screenshots',
    'out' => $root . '/dataset/benchmark_dataset.json',
    'ocr-cache' => $root . '/dataset/ocr_cache',
    'limit' => '0',
    'reuse-ocr' => '1',
];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

$srcDir = $args['src'];
$outPath = $args['out'];
$cacheDir = $args['ocr-cache'];
$limit = max(0, (int) $args['limit']);
$reuseOcr = $args['reuse-ocr'] !== '0';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Source dir missing: {$srcDir}\n");
    exit(1);
}
if (!defined('GOOGLE_CLOUD_VISION_API_KEY') || GOOGLE_CLOUD_VISION_API_KEY === '') {
    fwrite(STDERR, "GOOGLE_CLOUD_VISION_API_KEY is not configured\n");
    exit(1);
}
if (MockApiHelper::isEnabled()) {
    fwrite(STDERR, "ERROR: MOCK_API_MODE still enabled — refusing to run fake OCR.\n");
    exit(1);
}

foreach ([$cacheDir, dirname($outPath)] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }
}

$files = array_values(array_filter(
    scandir($srcDir) ?: [],
    static fn($f) => (bool) preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $f)
));
sort($files, SORT_STRING);
if ($limit > 0) {
    $files = array_slice($files, 0, $limit);
}

echo "Images=" . count($files) . "\n";
echo "Vision key configured; MOCK=" . (MockApiHelper::isEnabled() ? '1' : '0') . "\n";

$ref = new ReflectionClass(ChatModerationService::class);
/** @var ChatModerationService $moderation */
$moderation = $ref->newInstanceWithoutConstructor();
$regexMethod = $ref->getMethod('scanTextRegex');
$regexMethod->setAccessible(true);

$records = [];
$imageStats = [];
$errors = [];
$blockCount = 0;
$allowCount = 0;
$imageIndex = 0;

foreach ($files as $file) {
    $imageIndex++;
    $path = $srcDir . DIRECTORY_SEPARATOR . $file;
    $fraudId = sprintf('FRAUD_%03d', $imageIndex);
    echo sprintf("[%3d/%d] %s ... ", $imageIndex, count($files), $file);

    try {
        $ocrText = ocrWithVisionCached($path, $cacheDir, $reuseOcr);
    } catch (Throwable $e) {
        $errors[] = ['file' => $file, 'error' => $e->getMessage()];
        echo "OCR_FAIL\n";
        usleep(200_000);
        continue;
    }

    $utterances = finalizeUtterances(splitChatUtterances($ocrText));
    $imgBlock = 0;
    $imgAllow = 0;
    $seqBlock = 0;
    $seqAllow = 0;

    foreach ($utterances as $utt) {
        $raw = trim($utt);
        if ($raw === '' || shouldSkipUiNoise($raw)) {
            continue;
        }
        $anon = anonymizeText($raw);
        $label = classifyUtterance($anon, $moderation, $regexMethod);
        if ($label === null) {
            continue; // ambiguous chrome / too weak to keep
        }

        if ($label['ground_truth'] === 'BLOCK') {
            $seqBlock++;
            $suffix = 'PAYMENT';
            if ($seqBlock > 1) {
                $suffix = 'PAYMENT_' . $seqBlock;
            }
            $id = $fraudId . '_' . $suffix;
            $blockCount++;
            $imgBlock++;
        } else {
            $seqAllow++;
            $suffix = 'INQUIRY';
            if ($seqAllow > 1) {
                $suffix = 'INQUIRY_' . $seqAllow;
            }
            $id = $fraudId . '_' . $suffix;
            $allowCount++;
            $imgAllow++;
        }

        $records[] = [
            'id' => $id,
            'source_image' => $file,
            'text' => $anon,
            'ground_truth' => $label['ground_truth'],
            'category' => $label['category'],
        ];
    }

    $imageStats[] = [
        'source_image' => $file,
        'fraud_id' => $fraudId,
        'ocr_chars' => mb_strlen($ocrText),
        'block' => $imgBlock,
        'allow' => $imgAllow,
    ];
    echo "BLOCK={$imgBlock} ALLOW={$imgAllow}\n";
    usleep(120_000); // gentle Vision rate limit
}

$payload = [
    'schema_version' => '1.1',
    'dataset_name' => 'flixo_thai_c2c_fraud_screenshot_ocr',
    'language' => 'th',
    'source_dir' => 'raw_fraud_screenshots',
    'built_at' => gmdate('c'),
    'anonymization' => [
        'line_id' => '[LINE_ID]',
        'phone' => '[PHONE_NUM]',
        'bank_account' => '[BANK_ACCOUNT]',
        'promptpay' => '[PROMPTPAY]',
    ],
    'stats' => [
        'images_total' => count($files),
        'images_ocr_ok' => count($files) - count($errors),
        'images_ocr_failed' => count($errors),
        'messages_block' => $blockCount,
        'messages_allow' => $allowCount,
        'messages_total' => count($records),
    ],
    'errors' => $errors,
    'per_image' => $imageStats,
    'messages' => $records,
];

// User-requested top-level array shape for paper tooling:
// also write a flat array file alias.
$flat = $records;
$jsonPretty = json_encode($flat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$metaPretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonPretty === false || $metaPretty === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}

file_put_contents($outPath, $jsonPretty . "\n");
file_put_contents(dirname($outPath) . '/benchmark_dataset_meta.json', $metaPretty . "\n");

echo "\n=== Summary ===\n";
echo 'Images scanned: ' . count($files) . "\n";
echo "OCR failures: " . count($errors) . "\n";
echo "BLOCK messages: {$blockCount}\n";
echo "ALLOW messages: {$allowCount}\n";
echo "Wrote: {$outPath}\n";
echo 'Meta: ' . dirname($outPath) . "/benchmark_dataset_meta.json\n";

// ---------------- helpers ----------------

function ocrWithVisionCached(string $absolutePath, string $cacheDir, bool $reuse): string
{
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . hash('sha256', $absolutePath . '|' . filesize($absolutePath)) . '.txt';
    if ($reuse && is_file($cacheFile)) {
        return (string) file_get_contents($cacheFile);
    }

    $bytes = file_get_contents($absolutePath);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Cannot read image bytes');
    }

    $payload = [
        'requests' => [[
            'image' => ['content' => base64_encode($bytes)],
            'features' => [
                ['type' => 'DOCUMENT_TEXT_DETECTION', 'maxResults' => 1],
            ],
            'imageContext' => [
                'languageHints' => ['th', 'en'],
            ],
        ]],
    ];

    $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode(GOOGLE_CLOUD_VISION_API_KEY);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Vision request failed: ' . $err);
    }
    $data = json_decode($resp, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg));
    }

    $annotation = $data['responses'][0] ?? [];
    if (isset($annotation['error'])) {
        throw new RuntimeException((string) ($annotation['error']['message'] ?? 'Vision error'));
    }

    $text = (string) ($annotation['fullTextAnnotation']['text']
        ?? $annotation['textAnnotations'][0]['description']
        ?? '');
    $text = trim($text);
    file_put_contents($cacheFile, $text);
    return $text;
}

/**
 * Prefer full chat bubbles; drop fragments already covered by a longer sibling.
 *
 * @param list<string> $utterances
 * @return list<string>
 */
function finalizeUtterances(array $utterances): array
{
    $clean = [];
    foreach ($utterances as $u) {
        $u = trim(preg_replace('/\s+/u', ' ', $u) ?? $u);
        $u = preg_replace('/\s+\d{1,2}\s*พ\.\s*ย\..*$/u', '', $u) ?? $u;
        $u = trim($u);
        if ($u === '' || shouldSkipUiNoise($u)) {
            continue;
        }
        // Drop ultra-short fragments unless they are clear acknowledgements with context value
        if (mb_strlen($u) < 6) {
            continue;
        }
        $clean[] = $u;
    }

    usort($clean, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $kept = [];
    foreach ($clean as $cand) {
        $candKey = mb_strtolower($cand);
        $dominated = false;
        foreach ($kept as $k) {
            $kKey = mb_strtolower($k);
            if ($candKey === $kKey) {
                $dominated = true;
                break;
            }
            // substring fragment of an already-kept longer bubble
            if (mb_strlen($cand) < mb_strlen($k) && mb_strpos($kKey, $candKey) !== false) {
                $dominated = true;
                break;
            }
            // near-duplicate: Jaccard-ish token overlap
            if (tokenOverlap($candKey, $kKey) >= 0.85 && mb_strlen($cand) <= mb_strlen($k)) {
                $dominated = true;
                break;
            }
        }
        if (!$dominated) {
            $kept[] = $cand;
        }
    }

    // Restore roughly original reading order (shorter ids first by appearance heuristic: keep stable by first occurrence in OCR)
    return array_values($kept);
}

function tokenOverlap(string $a, string $b): float
{
    $ta = array_values(array_filter(preg_split('/\s+/u', $a) ?: []));
    $tb = array_values(array_filter(preg_split('/\s+/u', $b) ?: []));
    if ($ta === [] || $tb === []) {
        return 0.0;
    }
    $setB = array_fill_keys($tb, true);
    $inter = 0;
    foreach ($ta as $t) {
        if (isset($setB[$t])) {
            $inter++;
        }
    }
    return $inter / max(count($ta), count($tb));
}

/**
 * @return list<string>
 */
function splitChatUtterances(string $ocrText): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $ocrText);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $normalized)), static fn($l) => $l !== ''));

    $utterances = [];
    $buf = '';
    foreach ($lines as $line) {
        if (shouldSkipUiNoise($line)) {
            if ($buf !== '') {
                $utterances[] = $buf;
                $buf = '';
            }
            continue;
        }
        $startsNew = (bool) preg_match(
            '/^(โอน|ขาย|ราคา|ส่ง|ครับ|ค่ะ|คะ|พี่|น้อง|ได้|ยัง|เท่าไหร่|เบอร์|ไลน์|line|พร้อมเพย์|บัญชี|ธนาคาร|กสิกร|ไทยพาณิชย์|กรุงไทย|ออมสิน|scb|kbank|แจ้ง|สรุป|จ้า|แป[๊ป]บ)/iu',
            $line
        );
        if ($buf !== '' && $startsNew) {
            $utterances[] = $buf;
            $buf = $line;
            continue;
        }
        $buf = $buf === '' ? $line : ($buf . ' ' . $line);
        if (mb_strlen($buf) >= 160) {
            $utterances[] = $buf;
            $buf = '';
        }
    }
    if ($buf !== '') {
        $utterances[] = $buf;
    }

    foreach ($lines as $line) {
        if (mb_strlen($line) >= 10 && mb_strlen($line) <= 160 && !shouldSkipUiNoise($line)) {
            $utterances[] = $line;
        }
    }

    return $utterances;
}

function shouldSkipUiNoise(string $text): bool
{
    $t = mb_strtolower(trim($text));
    if (mb_strlen($t) < 2) {
        return true;
    }
    // Timestamp / calendar fragments often misclassified
    if (preg_match('/^\d{1,2}\s*พ\.?\s*ย\.?\s*\d{0,4}/u', $t)) {
        return true;
    }
    if (preg_match('/^\d{1,2}\s*[ก-ฮ]\.?\s*[ก-ฮ]\.?\s*\d{0,4}/u', $t)) {
        return true;
    }
    if (preg_match('/^\d{1,2}:\d{2}/', $t)) {
        return true;
    }
    if (preg_match('/^\d+%$/', $t)) {
        return true;
    }

    $noiseExactOrPrefix = [
        'marketplace', 'ดูรายละเอียด', 'ตัวเลือกเพิ่มเติม', 'รายการสินค้าบน',
        'active', 'online', 'typing', 'messenger', 'facebook',
        'battery', 'wifi', '4g', '5g', 'lte',
        'โอนเงินสำเร็จ', 'สถานะเพจ', 'การละเมิดมาตรฐานชุมชน',
        'ตอนนี้คุณสามารถให้คะแนน', 'แชร์ประสบการณ์', 'ขายรายนี้ได้แล้ว',
    ];
    foreach ($noiseExactOrPrefix as $n) {
        if ($t === $n || str_starts_with($t, $n) || mb_strpos($t, $n) !== false) {
            if (preg_match('/โอนเข้า|เลขบัญชี|พร้อมเพย์|แอดไลน์|แจ้งสลิป|\[BANK_ACCOUNT\]|\[LINE_ID\]|\[PHONE_NUM\]/u', $text)) {
                return false;
            }
            return true;
        }
    }

    // Bank-slip OCR dump without conversational bait
    if (preg_match('/xxx-\d|baym\d+|krungsri|ธนาคารกรุงศรี/iu', $t)
        && !preg_match('/โอนเข้า|โอนแล้ว|แจ้งสลิป|แอดไลน์|คุยนอก/u', $text)) {
        return true;
    }
    return false;
}

function anonymizeText(string $text): string
{
    $out = $text;

    // Line / social handles
    $out = preg_replace('/\b(?:line|ไลน์)\s*(?:id|ไอดี)?\s*[:：@]?\s*[a-z0-9._\-]{3,}/iu', 'Line ID [LINE_ID]', $out) ?? $out;
    $out = preg_replace('/\badd\s*line\s*[:：]?\s*[a-z0-9._\-]{3,}/iu', 'add line [LINE_ID]', $out) ?? $out;
    $out = preg_replace('/@[a-z][a-z0-9._]{2,}/iu', '[LINE_ID]', $out) ?? $out;
    $out = preg_replace('/\bid\s*:\s*[a-z0-9._\-]{3,}/iu', 'id: [LINE_ID]', $out) ?? $out;

    // Phone numbers (Thai mobile / spaced)
    $out = preg_replace('/\+?66[\s\-]?[689]\d[\d\s\-]{7,12}/u', '[PHONE_NUM]', $out) ?? $out;
    $out = preg_replace('/\b0[689][\d\s\-]{7,12}\b/u', '[PHONE_NUM]', $out) ?? $out;

    // PromptPay often same as phone; also generic 10-digit after keyword
    $out = preg_replace('/(พร้อมเพย์|promptpay)\s*[:：]?\s*[\d\-\s]{8,}/iu', '$1 [PROMPTPAY]', $out) ?? $out;

    // Bank account patterns (xxx-x-xxxxx-x / 10-12 digits near bank words)
    $out = preg_replace('/\b\d{3}[\s\-]?\d{1}[\s\-]?\d{5,6}[\s\-]?\d{1}\b/u', '[BANK_ACCOUNT]', $out) ?? $out;
    $out = preg_replace(
        '/((?:เลข)?บัญชี|บ\/ช|บช\.?|กสิกร|ไทยพาณิชย์|กรุงเทพ|กรุงไทย|ออมสิน|scb|kbank|bbl|ktb)\s*[:：]?\s*\d[\d\s\-]{8,}/iu',
        '$1 [BANK_ACCOUNT]',
        $out
    ) ?? $out;

    return trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
}

/**
 * @return array{ground_truth:string,category:string}|null
 */
function classifyUtterance(string $text, ChatModerationService $moderation, ReflectionMethod $regexMethod): ?array
{
    /** @var array<string, mixed> $regex */
    $regex = $regexMethod->invoke($moderation, $text);
    $score = (float) ($regex['risk_score'] ?? 0);
    $patterns = $regex['matched_patterns'] ?? [];

    $lower = mb_strtolower($text);

    // Strong BLOCK signals (even if regex score is mid).
    if (preg_match('/\[BANK_ACCOUNT\]|\[PROMPTPAY\]|\[PHONE_NUM\]|\[LINE_ID\]/u', $text)
        || preg_match('/โอนเข้าบัญชี|โอนเข้าบช|โอนตรง|โอนนอก|แจ้งสลิป|แอดไลน์|add\s*line|พร้อมเพย์|promptpay|เลขบัญชี|ไม่ผ่านแอป|ไม่ผ่านแอพ|คุยนอก/u', $lower)
        || in_array('bank_account', $patterns, true)
        || in_array('line_id', $patterns, true)
        || in_array('off_platform', $patterns, true)
        || in_array('phone_number', $patterns, true)
        || $score >= 55.0
    ) {
        $category = 'direct_payment_request';
        if (preg_match('/\[LINE_ID\]|แอดไลน์|add\s*line|ไลน์/u', $lower)) {
            $category = 'line_contact_request';
        } elseif (preg_match('/\[PHONE_NUM\]|โทร/u', $lower)) {
            $category = 'phone_contact_request';
        } elseif (preg_match('/\[PROMPTPAY\]|พร้อมเพย์|promptpay/u', $lower)) {
            $category = 'promptpay_share';
        } elseif (preg_match('/\[BANK_ACCOUNT\]|เลขบัญชี|โอนเข้า/u', $lower)) {
            $category = 'bank_account_share';
        } elseif (preg_match('/แจ้งสลิป|สลิป/u', $lower)) {
            $category = 'slip_request';
        }
        return ['ground_truth' => 'BLOCK', 'category' => $category];
    }

    // ALLOW: normal trade talk
    $allowHints = [
        'ขาย', 'ราคา', 'เท่าไหร่', 'ส่ง', 'ของ', 'สภาพ', 'รุ่น', 'สี', 'ลด', 'นัด',
        'ครับ', 'ค่ะ', 'คะ', 'ได้ไหม', 'ยังมี', 'รวมส่ง', 'รอบกี่โมง', 'เขียว', 'ละเมิด',
        'ถูกต้อง', 'ถูกครับ', 'โอเค', 'สนใจ', 'ดูรูป', 'กล่อง',
    ];
    $hit = false;
    foreach ($allowHints as $h) {
        if (mb_strpos($lower, $h) !== false) {
            $hit = true;
            break;
        }
    }
    // Bare price numbers as inquiry replies
    if (preg_match('/^\d{2,6}(?:\.\d+)?$/', $text) || preg_match('/^฿?\s?\d{2,3}(?:,\d{3})*(?:\s*บาท)?$/u', $text)) {
        $hit = true;
    }

    if ($hit && $score < 45.0) {
        // Require a minimally informative ALLOW utterance
        if (mb_strlen($text) < 8 && !preg_match('/^\d{2,6}/', $text)) {
            return null;
        }
        return ['ground_truth' => 'ALLOW', 'category' => 'normal_inquiry'];
    }

    // Drop residual OCR noise
    return null;
}
