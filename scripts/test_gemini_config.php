<?php
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/ChatModerationService.php';

echo 'Gemini configured: ' . (ChatModerationService::isGeminiConfigured() ? 'yes' : 'no') . "\n";
echo 'AI tier enabled: ' . (ChatModerationService::isAiTierEnabled() ? 'yes' : 'no') . "\n";
echo 'Model: ' . (defined('CHAT_MODERATION_GEMINI_MODEL') ? CHAT_MODERATION_GEMINI_MODEL : 'n/a') . "\n\n";

if (!ChatModerationService::isGeminiConfigured()) {
    echo "Set GEMINI_API_KEY in .env\n";
    exit(1);
}

$service = new ChatModerationService($conn);
$sample = 'ลดราคาให้หน่อยถ้าโอนมาเลย ไม่ต้องผ่านแอพนะ';
$result = $service->scanText($sample);

echo "Sample: {$sample}\n";
echo "Provider: {$result['provider']}\n";
echo "Verdict: {$result['verdict']} ({$result['risk_score']})\n";
if (!empty($result['reasons'])) {
    echo 'Reasons: ' . implode('; ', $result['reasons']) . "\n";
}
