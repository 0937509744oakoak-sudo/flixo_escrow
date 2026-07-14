#!/usr/bin/env php
<?php
/**
 * ตรวจสอบการตั้งค่า Google Cloud Vision API
 * Usage: php scripts/test_cloud_vision_config.php
 */
require_once __DIR__ . '/../includes/config.php';

echo "Google Cloud Vision configuration test\n";
echo "======================================\n\n";

$keyConfigured = defined('GOOGLE_CLOUD_VISION_API_KEY') && GOOGLE_CLOUD_VISION_API_KEY !== '';
echo 'API key configured: ' . ($keyConfigured ? 'YES' : 'NO') . "\n";
if ($keyConfigured) {
    $key = GOOGLE_CLOUD_VISION_API_KEY;
    echo 'Key prefix: ' . substr($key, 0, 8) . "...\n";
}
echo 'Fraud detection: ' . (getenv('IMAGE_FRAUD_PROVIDER') ?: 'auto') . "\n";
echo 'EXIF extension: ' . (function_exists('exif_read_data') ? 'yes' : 'no') . "\n\n";

if (!$keyConfigured) {
    fwrite(STDERR, "Set GOOGLE_CLOUD_VISION_API_KEY in .env\n");
    exit(1);
}

// 1x1 red JPEG — ใช้ quota น้อยที่สุด
$tinyJpeg = base64_decode(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEABj8Cf//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAT8hf//Z'
);

$payload = [
    'requests' => [[
        'image' => ['content' => base64_encode($tinyJpeg)],
        'features' => [
            ['type' => 'LABEL_DETECTION', 'maxResults' => 3],
        ],
    ]],
];

$url = 'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode(GOOGLE_CLOUD_VISION_API_KEY);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo "Connection error: {$curlErr}\n";
    exit(1);
}

$data = json_decode($resp ?: '{}', true);
$apiError = $data['error']['message'] ?? ($data['responses'][0]['error']['message'] ?? null);

if ($code >= 200 && $code < 300 && empty($apiError)) {
    $labels = [];
    foreach ($data['responses'][0]['labelAnnotations'] ?? [] as $label) {
        $labels[] = ($label['description'] ?? '') . ' (' . round((float)($label['score'] ?? 0) * 100) . '%)';
    }
    echo "API connection: OK\n";
    echo 'Labels: ' . ($labels ? implode(', ', $labels) : '(none)') . "\n\n";
    echo "Next steps:\n";
    echo "  php scripts/test_product_image_fraud.php\n";
    echo "  Upload a product listing in dashboard → Sell tab\n";
    exit(0);
}

echo "API connection: FAILED (HTTP {$code})\n";
if ($apiError) {
    echo "Error: {$apiError}\n\n";
}

printSetupGuide($apiError ?? '', $data);

exit(1);

function printSetupGuide(string $errorMessage, array $data): void {
    $projectId = null;
    if (preg_match('/project #(\d+)/', $errorMessage, $m)) {
        $projectId = $m[1];
    }

    echo "----------------------------------------\n";
    echo "วิธีตั้งค่า Google Cloud Vision (ทำตามลำดับ)\n";
    echo "----------------------------------------\n\n";

    echo "ขั้นที่ 1 — เปิด Billing (จำเป็นแม้ใช้ Free tier)\n";
    if ($projectId) {
        echo "  → https://console.developers.google.com/billing/enable?project={$projectId}\n";
    } else {
        echo "  → https://console.cloud.google.com/billing\n";
    }
    echo "  - สร้าง Billing Account แล้วผูกกับโปรเจกต์ที่สร้าง API key\n";
    echo "  - Free tier: 1,000 units/เดือน (LABEL_DETECTION = 1 unit/รูป)\n\n";

    echo "ขั้นที่ 2 — เปิดใช้ Cloud Vision API\n";
    if ($projectId) {
        echo "  → https://console.cloud.google.com/apis/library/vision.googleapis.com?project={$projectId}\n";
    } else {
        echo "  → https://console.cloud.google.com/apis/library/vision.googleapis.com\n";
    }
    echo "  - กดปุ่ม ENABLE\n\n";

    echo "ขั้นที่ 3 — จำกัด API Key (แนะนำ)\n";
    echo "  → https://console.cloud.google.com/apis/credentials\n";
    echo "  - เลือก API key ของคุณ\n";
    echo "  - Application restrictions: HTTP referrers (production) หรือ IP\n";
    echo "  - API restrictions: เลือกเฉพาะ \"Cloud Vision API\"\n\n";

    echo "ขั้นที่ 4 — ตั้ง Quota alert (ป้องกันค่าใช้จ่าย)\n";
    if ($projectId) {
        echo "  → https://console.cloud.google.com/apis/api/vision.googleapis.com/quotas?project={$projectId}\n";
    }
    echo "  - ตั้งแจ้งเตือนเมื่อใช้เกิน 80% ของ free tier\n\n";

    echo "ขั้นที่ 5 — ทดสอบอีกครั้ง\n";
    echo "  php scripts/test_cloud_vision_config.php\n";
    echo "  php scripts/test_product_image_fraud.php\n\n";

    if (stripos($errorMessage, 'billing') !== false) {
        echo "สาเหตุปัจจุบัน: ยังไม่ได้เปิด Billing สำหรับโปรเจกต์นี้\n";
    } elseif (stripos($errorMessage, 'API key not valid') !== false || stripos($errorMessage, 'API_KEY_INVALID') !== false) {
        echo "สาเหตุปัจจุบัน: API key ไม่ถูกต้องหรือถูกจำกัด\n";
    } elseif (stripos($errorMessage, 'disabled') !== false || stripos($errorMessage, 'not enabled') !== false) {
        echo "สาเหตุปัจจุบัน: ยังไม่ได้เปิด Cloud Vision API\n";
    }

    if (!empty($data['error']['status'])) {
        echo 'Status: ' . $data['error']['status'] . "\n";
    }
}
