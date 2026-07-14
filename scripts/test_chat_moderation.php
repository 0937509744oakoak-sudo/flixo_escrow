<?php
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/ChatModerationService.php';

$service = new ChatModerationService($conn);
$tests = [
    'สวัสดีครับ สนใจสินค้าครับ',
    'โอนเข้าบัญชี 1234567890 กสิกร',
    'ติดต่อไลน์ @testuser นะครับ',
    'โทร 081-234-5678',
    'https://line.me/ti/p/abc',
    'ชำระนอกระบบได้นะ โอนตรงเลย',
    'ลดราคาให้หน่อยถ้าโอนมาเลย ไม่ต้องผ่านแอพนะ',
    'ลดราคาให้ถ้าโอนมาเลย ไม่ผ่านแอพ',
];

foreach ($tests as $text) {
    $result = $service->scanText($text);
    echo $text . "\n";
    echo '  => ' . $result['verdict'] . ' (' . $result['risk_score'] . ') [' . $result['provider'] . "]\n";
    if ($result['reasons']) {
        echo '  => ' . implode('; ', $result['reasons']) . "\n";
    }
    echo "\n";
}
