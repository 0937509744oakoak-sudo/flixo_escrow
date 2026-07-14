<?php
// includes/OpenRouterService.php — OpenRouter chat completions (OpenAI-compatible)

class OpenRouterService {
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public static function isConfigured(): bool {
        return defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '';
    }

    public static function defaultModel(): string {
        if (defined('OPENROUTER_MODEL') && OPENROUTER_MODEL !== '') {
            return OPENROUTER_MODEL;
        }
        return 'google/gemini-2.5-flash';
    }

    /**
     * @return array<string, mixed>
     */
    public static function chatJson(string $prompt, ?string $model = null, float $temperature = 0.2): array {
        if (!self::isConfigured()) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }

        $model = $model ?: self::defaultModel();
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => 2048,
            'response_format' => ['type' => 'json_object'],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
        ];
        if (defined('BASE_URL') && BASE_URL !== '') {
            $headers[] = 'HTTP-Referer: ' . BASE_URL;
        }
        if (defined('SYSTEM_NAME') && SYSTEM_NAME !== '') {
            $headers[] = 'X-Title: ' . SYSTEM_NAME;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('OpenRouter request failed: ' . $curlError);
        }

        $data = json_decode($resp, true);
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? $data['error'] ?? 'OpenRouter API error';
            throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE));
        }

        if (!is_array($data)) {
            throw new RuntimeException('OpenRouter returned invalid response');
        }

        $rawText = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($rawText === '') {
            throw new RuntimeException('OpenRouter empty response');
        }

        $parsed = self::parseJsonText($rawText);
        if (!is_array($parsed)) {
            throw new RuntimeException('OpenRouter returned invalid JSON');
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parseJsonText(string $rawText): ?array {
        $text = trim($rawText);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $text = trim($m[1]);
        }
        $parsed = json_decode($text, true);
        return is_array($parsed) ? $parsed : null;
    }
}
