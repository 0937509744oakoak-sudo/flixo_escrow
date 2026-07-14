#!/usr/bin/env php
<?php
/**
 * ทดสอบ Product Image Fraud Detection
 * Usage: php scripts/test_product_image_fraud.php
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ProductImageFraudService.php';

$fixtureDir = realpath(__DIR__ . '/../tests/fixtures/fraud');
if (!$fixtureDir || !is_dir($fixtureDir)) {
    fwrite(STDERR, "Fixture directory missing: tests/fixtures/fraud\n");
    exit(1);
}

// สร้างรูปก็อป (ลบ EXIF ออก) จากรูปกล้องจริง
$source = $fixtureDir . DIRECTORY_SEPARATOR . 'phone_with_exif.jpg';
$stripped = $fixtureDir . DIRECTORY_SEPARATOR . 'copied_no_exif.jpg';
if (is_file($source) && !is_file($stripped)) {
    stripExifFromJpeg($source, $stripped);
    echo "Created stripped copy: copied_no_exif.jpg\n";
}

// สร้างรูปเล็กมาก (จำลองแชร์จาก LINE)
$tiny = $fixtureDir . DIRECTORY_SEPARATOR . 'tiny_chat_copy.jpg';
if (!is_file($tiny) && is_file($stripped)) {
    copy($stripped, $tiny);
    $data = file_get_contents($tiny);
    if ($data !== false && strlen($data) > 12000) {
        file_put_contents($tiny, substr($data, 0, 12000));
        echo "Created tiny copy: tiny_chat_copy.jpg (" . filesize($tiny) . " bytes)\n";
    }
}

$cases = [
    'phone_with_exif.jpg' => 'รูปถ่ายจริง (มี EXIF กล้อง+GPS)',
    'copied_no_exif.jpg' => 'รูปก็อป (ลบ EXIF ออกแล้ว)',
    'tiny_chat_copy.jpg' => 'รูปก็อปเล็ก (จำลองแชร์ LINE)',
];

$service = new ProductImageFraudService($conn);
$context = [
    'product_name' => 'iPhone 15 Pro Max 256GB',
    'category' => 'อิเล็กทรอนิกส์',
    'description' => 'สภาพมือสอง ใช้งานปกติ',
];

echo "\n========================================\n";
echo "FLIXO Product Image Fraud Test\n";
echo "========================================\n";
echo 'EXIF extension: ' . (function_exists('exif_read_data') ? 'yes' : 'no') . "\n";
echo 'Cloud Vision key: ' . (ProductImageFraudService::isCloudVisionConfigured() ? 'configured' : 'NOT set') . "\n";
echo 'Fraud detection: ' . (ProductImageFraudService::isEnabled() ? 'enabled' : 'disabled') . "\n\n";

$passed = 0;
$failed = 0;

foreach ($cases as $file => $label) {
    $path = $fixtureDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        echo "[SKIP] {$label} — ไม่พบไฟล์ {$file}\n\n";
        continue;
    }

    echo "----------------------------------------\n";
    echo "📷 {$label}\n";
    echo "   File: {$file} (" . formatBytes((int)filesize($path)) . ")\n";
    printExifSummary($path);

    $start = microtime(true);
    $result = $service->scanImages([$path], $context);
    $elapsed = round((microtime(true) - $start) * 1000);

    $perImage = $result['per_image'][0] ?? [];
    $raw = $perImage['raw'] ?? [];
    $visionSkipped = $raw['vision_skipped'] ?? false;

    echo "   Provider: " . ($perImage['provider'] ?? $result['provider'] ?? '-') . "\n";
    echo "   Vision API called: " . ($visionSkipped ? 'NO (EXIF passed)' : 'YES') . "\n";
    echo "   Risk score: " . number_format((float)($perImage['risk_score'] ?? 0), 1) . "/100\n";
    echo "   Verdict: " . strtoupper((string)($perImage['verdict'] ?? '-')) . "\n";
    echo "   Status: " . ($result['status'] ?? '-') . "\n";
    echo "   Time: {$elapsed} ms\n";

    if (!empty($perImage['reasons'])) {
        echo "   Reasons:\n";
        foreach ($perImage['reasons'] as $reason) {
            echo "     - {$reason}\n";
        }
    }

    $expectVision = in_array($file, ['copied_no_exif.jpg', 'tiny_chat_copy.jpg'], true);
    $expectNoVision = ($file === 'phone_with_exif.jpg');
    $visionCalled = !$visionSkipped;

    if ($expectNoVision && !$visionCalled && ($perImage['verdict'] ?? '') === 'pass') {
        echo "   ✅ ตามที่คาด: รูปจริงผ่าน EXIF ไม่เรียก Vision\n";
        $passed++;
    } elseif ($expectVision && $visionCalled) {
        echo "   ✅ ตามที่คาด: รูปน่าสงสัย → ส่งตรวจ Cloud Vision\n";
        $passed++;
    } elseif ($expectVision && !$visionCalled && !ProductImageFraudService::isCloudVisionConfigured()) {
        echo "   ⚠️  Vision ไม่ได้ตั้งค่า — ใช้ EXIF flag แทน\n";
        $passed++;
    } else {
        echo "   ⚠️  ผลลัพธ์ไม่ตรงที่คาด (ตรวจสอบด้วยตา)\n";
        $failed++;
    }

    if (isset($raw['vision']['web_full_matches'])) {
        echo "   Web full matches: " . (int)$raw['vision']['web_full_matches'] . "\n";
    }
    echo "\n";
}

echo "========================================\n";
echo "Summary: {$passed} passed expectations, {$failed} unexpected\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);

function stripExifFromJpeg(string $source, string $dest): void {
    $data = file_get_contents($source);
    if ($data === false || strlen($data) < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
        throw new RuntimeException('Not a valid JPEG: ' . $source);
    }

    $out = "\xFF\xD8";
    $offset = 2;
    $len = strlen($data);

    while ($offset + 4 <= $len) {
        if ($data[$offset] !== "\xFF") {
            break;
        }
        $marker = $data[$offset + 1];
        if ($marker === "\xDA") {
            $out .= substr($data, $offset);
            file_put_contents($dest, $out);
            return;
        }
        $segLen = unpack('n', substr($data, $offset + 2, 2))[1];
        if ($segLen < 2) {
            break;
        }
        $segment = substr($data, $offset, 2 + $segLen);
        $isExif = ($marker === "\xE1" && substr($segment, 4, 6) === "Exif\x00\x00");
        $isApp13 = ($marker === "\xED");
        if (!$isExif && !$isApp13) {
            $out .= $segment;
        }
        $offset += 2 + $segLen;
    }

    file_put_contents($dest, $data);
}

function printExifSummary(string $path): void {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'unknown';
    echo "   MIME: {$mime}\n";

    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        echo "   EXIF: n/a\n";
        return;
    }

    $exif = @exif_read_data($path, 'ANY_TAG', true);
    if (!is_array($exif) || $exif === []) {
        echo "   EXIF: ไม่มีข้อมูล\n";
        return;
    }

    $make = $exif['IFD0']['Make'] ?? ($exif['Make'] ?? '-');
    $model = $exif['IFD0']['Model'] ?? ($exif['Model'] ?? '-');
    $dt = $exif['EXIF']['DateTimeOriginal'] ?? ($exif['DateTimeOriginal'] ?? '-');
    $gps = isset($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLongitude']) ? 'yes' : 'no';
    echo "   EXIF: Make={$make}, Model={$model}, DateTime={$dt}, GPS={$gps}\n";
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
