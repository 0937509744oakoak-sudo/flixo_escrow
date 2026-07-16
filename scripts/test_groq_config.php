<?php
// scripts/test_groq_config.php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/GroqService.php';
require __DIR__ . '/../includes/DisputeAssistantService.php';
require __DIR__ . '/../includes/ChatModerationService.php';

echo 'GROQ_API_KEY set: ' . (GroqService::isConfigured() ? 'yes' : 'no') . PHP_EOL;
echo 'GROQ_MODEL: ' . GroqService::defaultModel() . PHP_EOL;
echo 'Dispute AI configured: ' . (DisputeAssistantService::isAiConfigured() ? 'yes' : 'no') . PHP_EOL;
echo 'Dispute Groq: ' . (DisputeAssistantService::isGroqConfigured() ? 'yes' : 'no') . PHP_EOL;
echo 'Chat AI configured: ' . (ChatModerationService::isAiConfigured() ? 'yes' : 'no') . PHP_EOL;

if (!GroqService::isConfigured()) {
    fwrite(STDERR, "Set GROQ_API_KEY in .env or environment.\n");
    exit(1);
}

try {
    $result = GroqService::chatJson(
        'Return ONLY valid JSON: {"ok":true,"msg":"pong","provider":"groq"}',
        null,
        0
    );
    echo 'Ping OK: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ping FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
