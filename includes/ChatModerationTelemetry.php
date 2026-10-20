<?php
// includes/ChatModerationTelemetry.php
// Lightweight, production-safe telemetry for cascaded chat moderation.
// Primary sink: append-only JSONL (non-blocking relative to chat UX).
// Optional sink: deferred DB insert via register_shutdown_function.

class ChatModerationTelemetry {
    public const TIER_1_REGEX = 'TIER_1_REGEX';
    public const TIER_2_LLM = 'TIER_2_LLM';
    public const TIER_3_FALLBACK = 'TIER_3_FALLBACK';
    public const TIER_SKIPPED = 'TIER_SKIPPED';

    public const VERDICT_ALLOW = 'ALLOW';
    public const VERDICT_FLAG = 'FLAG';
    public const VERDICT_BLOCK = 'BLOCK';

    /** @var list<array<string, mixed>> */
    private static array $pendingDbRows = [];
    private static bool $shutdownRegistered = false;

    public static function isEnabled(): bool {
        if (defined('CHAT_MODERATION_TELEMETRY_ENABLED') && !CHAT_MODERATION_TELEMETRY_ENABLED) {
            return false;
        }
        $configured = getenv('CHAT_MODERATION_TELEMETRY_ENABLED');
        if ($configured === '0' || $configured === 'false') {
            return false;
        }
        return true;
    }

    public static function isDbEnabled(): bool {
        if (!self::isEnabled()) {
            return false;
        }
        if (defined('CHAT_MODERATION_TELEMETRY_DB') && !CHAT_MODERATION_TELEMETRY_DB) {
            return false;
        }
        $configured = getenv('CHAT_MODERATION_TELEMETRY_DB');
        if ($configured === '0' || $configured === 'false') {
            return false;
        }
        return true;
    }

    /**
     * Map internal verdict (pass|flag|reject) → research vocabulary (ALLOW|FLAG|BLOCK).
     */
    public static function mapVerdict(string $verdict): string {
        return match (strtolower(trim($verdict))) {
            'reject' => self::VERDICT_BLOCK,
            'flag' => self::VERDICT_FLAG,
            default => self::VERDICT_ALLOW,
        };
    }

    /**
     * Anonymized content fingerprint — never store plaintext in telemetry sinks.
     */
    public static function hashMessage(string $text): string {
        $pepper = self::pepper();
        return hash_hmac('sha256', $text, $pepper);
    }

    /**
     * Rough token estimate when provider usage is unavailable.
     * Assumes ~4 chars/token for mixed Thai+English prompts.
     */
    public static function estimateTokens(string $promptText, int $expectedCompletionTokens = 180): int {
        $promptTokens = (int) max(1, (int) ceil(mb_strlen($promptText) / 4));
        return $promptTokens + max(0, $expectedCompletionTokens);
    }

    /**
     * Approximate USD cost from provider + token count (research proxy, not billing).
     */
    public static function estimateCostUsd(string $provider, int $estimatedTokens): float {
        if ($estimatedTokens <= 0 || $provider === 'none' || $provider === 'heuristic' || $provider === 'off') {
            return 0.0;
        }
        // Blended $/1M tokens (input+output average) — configurable via env when needed.
        $perMillion = match ($provider) {
            'groq' => defined('CHAT_MODERATION_COST_GROQ_PER_M')
                ? (float) CHAT_MODERATION_COST_GROQ_PER_M : 0.70,
            'openrouter' => defined('CHAT_MODERATION_COST_OPENROUTER_PER_M')
                ? (float) CHAT_MODERATION_COST_OPENROUTER_PER_M : 0.30,
            'gemini' => defined('CHAT_MODERATION_COST_GEMINI_PER_M')
                ? (float) CHAT_MODERATION_COST_GEMINI_PER_M : 0.20,
            'mock-llm', 'mock' => 0.0,
            default => 0.50,
        };
        return round(($estimatedTokens / 1_000_000) * $perMillion, 8);
    }

    /**
     * Build a complete telemetry record from scan timing + cascade outcome.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function buildRecord(
        string $messageHash,
        string $tierReached,
        string $providerUsed,
        float $regexScore,
        ?float $llmConfidence,
        string $internalVerdict,
        float $latencyTier1Ms,
        ?float $latencyTier2Ms,
        float $totalLatencyMs,
        ?int $estimatedTokens,
        ?float $estimatedCostUsd,
        array $extra = []
    ): array {
        $record = [
            'ts' => gmdate('c'),
            'message_id' => $extra['message_id'] ?? null,
            'message_hash' => $messageHash,
            'content_type' => (string) ($extra['content_type'] ?? 'text'),
            'tier_reached' => $tierReached,
            'provider_used' => $providerUsed,
            'regex_score' => round($regexScore, 2),
            'llm_confidence' => $llmConfidence !== null ? round($llmConfidence, 2) : null,
            'final_verdict' => self::mapVerdict($internalVerdict),
            'latency_tier1_ms' => round($latencyTier1Ms, 3),
            'latency_tier2_ms' => $latencyTier2Ms !== null ? round($latencyTier2Ms, 3) : null,
            'total_latency_ms' => round($totalLatencyMs, 3),
            'estimated_tokens' => $estimatedTokens,
            'estimated_cost_usd' => $estimatedCostUsd !== null ? round($estimatedCostUsd, 8) : 0.0,
            'escalated' => $tierReached === self::TIER_2_LLM || $tierReached === self::TIER_3_FALLBACK,
            'matched_patterns' => $extra['matched_patterns'] ?? [],
            'provider_errors' => $extra['provider_errors'] ?? [],
            'text_length' => (int) ($extra['text_length'] ?? 0),
            'soft_trigger' => (bool) ($extra['soft_trigger'] ?? false),
            'soft_cues' => array_values($extra['soft_cues'] ?? []),
        ];

        return $record;
    }

    /**
     * Persist telemetry without blocking the chat critical path.
     * JSONL write is immediate (append); DB insert is deferred to shutdown.
     *
     * @param array<string, mixed> $record
     */
    public static function persist(?mysqli $conn, array $record): void {
        if (!self::isEnabled()) {
            return;
        }

        try {
            self::appendJsonl($record);
        } catch (Throwable $e) {
            error_log('ChatModerationTelemetry JSONL: ' . $e->getMessage());
        }

        if ($conn instanceof mysqli && self::isDbEnabled()) {
            self::$pendingDbRows[] = ['conn' => $conn, 'record' => $record];
            self::registerShutdownFlush();
        }
    }

    /**
     * Attach message_id after INSERT and re-persist (JSONL line + deferred DB).
     *
     * @param array<string, mixed> $telemetry
     */
    public static function persistWithMessageId(?mysqli $conn, array $telemetry, ?int $messageId): void {
        if ($messageId !== null) {
            $telemetry['message_id'] = $messageId;
        }
        self::persist($conn, $telemetry);
    }

    public static function logFilePath(): ?string {
        $dir = defined('LOG_PATH') && LOG_PATH !== ''
            ? rtrim(LOG_PATH, '/\\')
            : (defined('ROOT_PATH') ? ROOT_PATH . '/logs' : dirname(__DIR__) . '/logs');

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/chat_moderation_telemetry.jsonl';
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function appendJsonl(array $record): void {
        $path = self::logFilePath();
        if ($path === null) {
            return;
        }

        // Strip any accidental plaintext fields.
        unset($record['message_preview'], $record['message_text'], $record['raw_text']);

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function registerShutdownFlush(): void {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'flushPendingDb']);
    }

    public static function flushPendingDb(): void {
        if (self::$pendingDbRows === []) {
            return;
        }
        $rows = self::$pendingDbRows;
        self::$pendingDbRows = [];

        foreach ($rows as $item) {
            /** @var mysqli $conn */
            $conn = $item['conn'];
            /** @var array<string, mixed> $record */
            $record = $item['record'];
            try {
                self::insertDb($conn, $record);
            } catch (Throwable $e) {
                error_log('ChatModerationTelemetry DB: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function insertDb(mysqli $conn, array $record): void {
        if (!self::hasTelemetryTable($conn)) {
            return;
        }

        $messageId = isset($record['message_id']) && $record['message_id'] !== null && (int) $record['message_id'] > 0
            ? (int) $record['message_id']
            : null;
        $messageHash = (string) ($record['message_hash'] ?? '');
        $contentType = (string) ($record['content_type'] ?? 'text');
        $tierReached = (string) ($record['tier_reached'] ?? self::TIER_1_REGEX);
        $providerUsed = (string) ($record['provider_used'] ?? 'none');
        $regexScore = (float) ($record['regex_score'] ?? 0);
        $llmConfidence = array_key_exists('llm_confidence', $record) && $record['llm_confidence'] !== null
            ? (float) $record['llm_confidence']
            : null;
        $finalVerdict = (string) ($record['final_verdict'] ?? self::VERDICT_ALLOW);
        $latencyTier1 = (float) ($record['latency_tier1_ms'] ?? 0);
        $latencyTier2 = array_key_exists('latency_tier2_ms', $record) && $record['latency_tier2_ms'] !== null
            ? (float) $record['latency_tier2_ms']
            : null;
        $totalLatency = (float) ($record['total_latency_ms'] ?? 0);
        $estimatedTokens = array_key_exists('estimated_tokens', $record) && $record['estimated_tokens'] !== null
            ? (int) $record['estimated_tokens']
            : null;
        $estimatedCost = (float) ($record['estimated_cost_usd'] ?? 0);
        $escalated = !empty($record['escalated']) ? 1 : 0;
        $patternsJson = !empty($record['matched_patterns'])
            ? json_encode($record['matched_patterns'], JSON_UNESCAPED_UNICODE)
            : null;
        $errorsJson = !empty($record['provider_errors'])
            ? json_encode($record['provider_errors'], JSON_UNESCAPED_UNICODE)
            : null;
        $textLength = (int) ($record['text_length'] ?? 0);

        // Use JSON meta column-less insert with explicit NULLs via conditional SQL fragments
        // to avoid mysqli bind_param quirks with nullable doubles on older PHP builds.
        $sql = 'INSERT INTO chat_moderation_telemetry
            (message_id, message_hash, content_type, tier_reached, provider_used,
             regex_score, llm_confidence, final_verdict,
             latency_tier1_ms, latency_tier2_ms, total_latency_ms,
             estimated_tokens, estimated_cost_usd, escalated,
             matched_patterns, provider_errors, text_length)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'issssddsdddidissi',
            $messageId,
            $messageHash,
            $contentType,
            $tierReached,
            $providerUsed,
            $regexScore,
            $llmConfidence,
            $finalVerdict,
            $latencyTier1,
            $latencyTier2,
            $totalLatency,
            $estimatedTokens,
            $estimatedCost,
            $escalated,
            $patternsJson,
            $errorsJson,
            $textLength
        );
        $stmt->execute();
        $stmt->close();
    }

    private static function hasTelemetryTable(mysqli $conn): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $conn->query("SHOW TABLES LIKE 'chat_moderation_telemetry'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private static function pepper(): string {
        if (defined('CHAT_MODERATION_TELEMETRY_SALT') && CHAT_MODERATION_TELEMETRY_SALT !== '') {
            return (string) CHAT_MODERATION_TELEMETRY_SALT;
        }
        $env = getenv('CHAT_MODERATION_TELEMETRY_SALT');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        if (defined('WEBHOOK_SECRET') && WEBHOOK_SECRET !== '') {
            return (string) WEBHOOK_SECRET;
        }
        return 'flixo-chat-moderation-telemetry';
    }
}
