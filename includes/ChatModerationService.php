<?php
// includes/ChatModerationService.php
// Tier 1: Regex/heuristics (free) → Tier 2: AI via OpenRouter/Gemini (suspicious) → Vision OCR (images)

require_once __DIR__ . '/OpenRouterService.php';
require_once __DIR__ . '/GroqService.php';

class ChatModerationService {
    public const PROVIDER_OFF = 'off';
    public const PROVIDER_REGEX = 'regex';
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_GROQ = 'groq';
    public const PROVIDER_CLOUD_VISION = 'cloud_vision';
    public const VERDICT_PASS = 'pass';
    public const VERDICT_FLAG = 'flag';
    public const VERDICT_REJECT = 'reject';

    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public static function isEnabled(): bool {
        if (defined('CHAT_MODERATION_ENABLED') && !CHAT_MODERATION_ENABLED) {
            return false;
        }
        $configured = getenv('CHAT_MODERATION_ENABLED');
        if ($configured === '0' || $configured === 'false') {
            return false;
        }
        return true;
    }

    public static function isCloudVisionConfigured(): bool {
        return defined('GOOGLE_CLOUD_VISION_API_KEY') && GOOGLE_CLOUD_VISION_API_KEY !== '';
    }

    public static function isGeminiConfigured(): bool {
        return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
    }

    public static function isOpenRouterConfigured(): bool {
        return OpenRouterService::isConfigured();
    }

    public static function isGroqConfigured(): bool {
        return GroqService::isConfigured();
    }

    public static function isAiConfigured(): bool {
        return self::isGroqConfigured() || self::isOpenRouterConfigured() || self::isGeminiConfigured();
    }

    public static function isAiTierEnabled(): bool {
        return self::isAiConfigured()
            && (!defined('CHAT_MODERATION_AI_ENABLED') || CHAT_MODERATION_AI_ENABLED);
    }

    /**
     * @return string[]
     */
    public static function getOffPlatformKeywords(): array {
        return [
            'โอนนอก', 'โอนตรง', 'โอนเงินโดยตรง', 'ไม่ผ่านระบบ', 'นอกระบบ',
            'ไม่ผ่านแอพ', 'ไม่ผ่านแอป', 'ไม่ต้องผ่านแอพ', 'ไม่ต้องผ่านแอป',
            'คุยนอกแอพ', 'คุยนอกแอป', 'ผ่านแอพ', 'ผ่านแอป',
            'โอนเข้าบัญชี', 'โอนให้เลย', 'โอนมาเลย', 'ชำระนอก', 'จ่ายนอก',
            'ติดต่อไลน์', 'แอดไลน์', 'add line', 'addline', 'id line', 'ไลน์ไอดี',
            'โทรมา', 'โทรหา', 'เบอร์โทร', 'เบอร์มือถือ', 'เลขบัญชี', 'บัญชีธนาคาร',
            'พร้อมเพย์', 'promptpay', 'โอนตรงเข้า', 'หลีกเลี่ยงค่าธรรมเนียม',
            'โอนภายนอก', 'ชำระภายนอก', 'โอนเงินภายนอก', 'ติดต่อนอกแพลตฟอร์ม',
        ];
    }

    /**
     * @return string[]
     */
    public static function getBankKeywords(): array {
        return [
            'กสิกร', 'ไทยพาณิชย์', 'กรุงเทพ', 'กรุงไทย', 'ออมสิน', 'ทหารไทย',
            'ธ.ก.ส', 'ธกส', 'kbank', 'scb', 'bbl', 'ktb', 'gsb', 'ttb', 'bay',
            'promptpay', 'พร้อมเพย์', 'เลขบัญชี', 'บัญชีธนาคาร', 'บช.', 'บ/ช',
        ];
    }

    public function shouldScan(string $sender, string $receiver, string $message): bool {
        if (!self::isEnabled()) {
            return false;
        }
        if ($sender === 'System' || $receiver === 'System') {
            return false;
        }
        if (preg_match('/^\[(PRODUCT|OFFER):\d+\]$/', trim($message))) {
            return false;
        }
        return true;
    }

    public function scanText(string $text): array {
        if (!self::isEnabled()) {
            return $this->skippedResult();
        }

        $regexResult = $this->scanTextRegex($text);

        if (!$this->shouldTriggerGemini($regexResult, $text)) {
            return $regexResult;
        }

        try {
            $aiResult = $this->analyzeWithPreferredAi($text, $regexResult);
            return $this->mergeResults($regexResult, $aiResult);
        } catch (Throwable $e) {
            error_log('ChatModerationService AI: ' . $e->getMessage());
            $regexResult['raw']['ai_error'] = $e->getMessage();
            if ($regexResult['verdict'] === self::VERDICT_PASS && $this->hasSuspiciousDigitRun($text)) {
                $regexResult['verdict'] = self::VERDICT_FLAG;
                $regexResult['status'] = 'flagged';
                $regexResult['reasons'][] = 'ไม่สามารถตรวจสอบด้วย AI ได้ชั่วคราว — ข้อความมีลักษณะน่าสงสัย';
            }
            return $regexResult;
        }
    }

    private function scanTextRegex(string $text): array {
        $normalized = $this->normalizeText($text);
        $matches = [];
        $reasons = [];
        $riskScore = 0.0;

        if ($this->matchPhoneNumber($normalized, $text)) {
            $matches[] = 'phone_number';
            $reasons[] = 'พบเบอร์โทรศัพท์ในข้อความ';
            $riskScore = max($riskScore, 55.0);
        }

        if ($this->matchBankAccount($normalized, $text)) {
            $matches[] = 'bank_account';
            $reasons[] = 'พบเลขบัญชีธนาคารหรือตัวเลขบัญชี';
            $riskScore = max($riskScore, 70.0);
        }

        $bankKeywords = $this->matchKeywords($text, self::getBankKeywords());
        if ($bankKeywords) {
            $matches[] = 'bank_keyword';
            $reasons[] = 'พบคำที่เกี่ยวกับบัญชีธนาคาร (' . implode(', ', array_slice($bankKeywords, 0, 2)) . ')';
            $riskScore = max($riskScore, 45.0);
        }

        if ($this->matchExternalUrl($text)) {
            $matches[] = 'external_url';
            $reasons[] = 'พบลิงก์ภายนอกหรือช่องทางติดต่อนอกแพลตฟอร์ม';
            $riskScore = max($riskScore, 50.0);
        }

        $offPlatform = $this->matchKeywords($text, self::getOffPlatformKeywords());
        if ($offPlatform) {
            $matches[] = 'off_platform';
            $reasons[] = 'พบคำชักชวนโอน/ติดต่อนอกระบบ (' . implode(', ', array_slice($offPlatform, 0, 2)) . ')';
            $riskScore = max($riskScore, 65.0);
        }

        if ($this->matchDiscountBypassBait($text)) {
            $matches[] = 'discount_bypass';
            $reasons[] = 'พบการชวนโอนนอกระบบเพื่อลดราคา';
            $riskScore = max($riskScore, 72.0);
        }

        if ($this->matchLineId($text)) {
            $matches[] = 'line_id';
            $reasons[] = 'พบ Line ID หรือช่องทางติดต่อภายนอก';
            $riskScore = max($riskScore, 55.0);
        }

        $riskScore = min(100.0, $riskScore + (count($matches) > 1 ? 10.0 : 0.0));
        $verdict = $this->resolveVerdict($riskScore);

        return [
            'provider' => self::PROVIDER_REGEX,
            'risk_score' => $riskScore,
            'verdict' => $verdict,
            'status' => $this->verdictToStatus($verdict),
            'matched_patterns' => $matches,
            'reasons' => array_values(array_unique($reasons)),
            'raw' => ['tier' => 1, 'text_length' => mb_strlen($text)],
        ];
    }
    public function scanImage(string $absolutePath): array {
        if (!self::isEnabled() || !is_file($absolutePath)) {
            return $this->skippedResult();
        }

        if (!self::isCloudVisionConfigured()) {
            return $this->skippedResult();
        }

        try {
            $ocrText = $this->extractTextWithCloudVision($absolutePath);
            if (trim($ocrText) === '') {
                return [
                    'provider' => self::PROVIDER_CLOUD_VISION,
                    'risk_score' => 0,
                    'verdict' => self::VERDICT_PASS,
                    'status' => 'passed',
                    'matched_patterns' => [],
                    'reasons' => [],
                    'raw' => ['tier' => 2, 'ocr_empty' => true],
                ];
            }

            $textResult = $this->scanText($ocrText);
            $textResult['provider'] = self::PROVIDER_REGEX . '+' . self::PROVIDER_CLOUD_VISION;
            $textResult['raw']['tier'] = 2;
            $textResult['raw']['ocr_preview'] = mb_substr($ocrText, 0, 200);
            if (!empty($textResult['reasons'])) {
                $textResult['reasons'][] = 'ตรวจพบในรูปภาพที่ส่ง';
            }
            return $textResult;
        } catch (Throwable $e) {
            error_log('ChatModerationService Vision: ' . $e->getMessage());
            return [
                'provider' => self::PROVIDER_CLOUD_VISION,
                'risk_score' => 40.0,
                'verdict' => self::VERDICT_FLAG,
                'status' => 'flagged',
                'matched_patterns' => ['vision_error'],
                'reasons' => ['ไม่สามารถตรวจสอบรูปภาพด้วย AI ได้ชั่วคราว'],
                'raw' => ['tier' => 2, 'error' => $e->getMessage()],
            ];
        }
    }

    public function formatUserMessage(array $result): string {
        $reasons = $result['reasons'] ?? [];
        $base = 'ข้อความนี้ไม่สามารถส่งได้ เพื่อความปลอดภัยกรุณาซื้อขายผ่านระบบ Escrow ของ FLIXO เท่านั้น';
        if (empty($reasons)) {
            return $base;
        }
        return $base . ' — ' . implode('; ', array_slice($reasons, 0, 2));
    }

    public function logScan(
        ?int $messageId,
        string $sender,
        string $receiver,
        string $contentType,
        string $preview,
        array $result
    ): void {
        if (!$this->hasScansTable()) {
            return;
        }

        $patternsJson = !empty($result['matched_patterns'])
            ? json_encode($result['matched_patterns'], JSON_UNESCAPED_UNICODE) : null;
        $reasonsJson = !empty($result['reasons'])
            ? json_encode($result['reasons'], JSON_UNESCAPED_UNICODE) : null;
        $rawJson = !empty($result['raw'])
            ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE) : null;
        $previewTrim = mb_substr($preview, 0, 500);
        $provider = (string)($result['provider'] ?? self::PROVIDER_REGEX);
        $verdict = (string)($result['verdict'] ?? self::VERDICT_PASS);
        $riskScore = (float)($result['risk_score'] ?? 0);

        $sql = "INSERT INTO chat_moderation_scans
                (message_id, sender_name, receiver_name, content_type, message_preview,
                 provider, verdict, risk_score, matched_patterns, reasons, raw_response)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            'issssssdsss',
            $messageId,
            $sender,
            $receiver,
            $contentType,
            $previewTrim,
            $provider,
            $verdict,
            $riskScore,
            $patternsJson,
            $reasonsJson,
            $rawJson
        );
        $stmt->execute();
    }

    public function updateMessageModeration(int $messageId, array $result): void {
        if (!$this->hasModerationColumns()) {
            return;
        }

        $status = $this->verdictToModerationStatus((string)($result['verdict'] ?? self::VERDICT_PASS));
        $score = (float)($result['risk_score'] ?? 0);
        $reasonsJson = !empty($result['reasons'])
            ? json_encode($result['reasons'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->conn->prepare(
            'UPDATE messages SET moderation_status = ?, moderation_score = ?, moderation_reasons = ? WHERE id = ?'
        );
        $stmt->bind_param('sdsi', $status, $score, $reasonsJson, $messageId);
        $stmt->execute();
    }

    /**
     * @return mysqli_result|false
     */
    public function getReviewQueue(int $limit = 100) {
        if (!$this->hasScansTable()) {
            return false;
        }

        $limit = max(1, min(200, $limit));
        $sql = "SELECT s.*, m.message, m.image_path, m.created_at AS message_created_at
                FROM chat_moderation_scans s
                LEFT JOIN messages m ON m.id = s.message_id
                WHERE s.review_status = 'pending'
                  AND s.verdict IN ('flag', 'reject')
                ORDER BY s.risk_score DESC, s.created_at DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function dismissScan(int $scanId, string $adminUser): bool {
        if (!$this->hasScansTable()) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "UPDATE chat_moderation_scans SET review_status = 'dismissed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('si', $adminUser, $scanId);
        return $stmt->execute();
    }

    public function actionScan(int $scanId, string $adminUser): bool {
        if (!$this->hasScansTable()) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "UPDATE chat_moderation_scans SET review_status = 'actioned', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('si', $adminUser, $scanId);
        return $stmt->execute();
    }

    public function getPendingCount(): int {
        if (!$this->hasScansTable()) {
            return 0;
        }
        $result = $this->conn->query(
            "SELECT COUNT(*) AS cnt FROM chat_moderation_scans WHERE review_status = 'pending' AND verdict IN ('flag', 'reject')"
        );
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }

    private function shouldTriggerGemini(array $regexResult, string $text): bool {
        if (!self::isAiTierEnabled()) {
            return false;
        }

        $minScore = defined('CHAT_MODERATION_AI_MIN_SCORE') ? (float)CHAT_MODERATION_AI_MIN_SCORE : 25.0;
        $skipAbove = defined('CHAT_MODERATION_AI_SKIP_ABOVE_SCORE') ? (float)CHAT_MODERATION_AI_SKIP_ABOVE_SCORE : 80.0;
        $score = (float)($regexResult['risk_score'] ?? 0);

        if ($score >= $skipAbove) {
            return false;
        }
        if ($score >= $minScore) {
            return true;
        }
        if ($score === 0.0 && $this->hasSuspiciousDigitRun($text)) {
            return true;
        }

        return false;
    }

    private function hasSuspiciousDigitRun(string $text): bool {
        $digits = preg_replace('/\D/u', '', $text) ?? '';
        return strlen($digits) >= 8 && !preg_match('/^0[689]\d{8}$/', $digits);
    }

    private function analyzeWithPreferredAi(string $text, array $regexResult): array {
        $errors = [];

        if (self::isGroqConfigured()) {
            try {
                return $this->analyzeWithGroq($text, $regexResult);
            } catch (Throwable $e) {
                $errors[] = 'Groq: ' . $e->getMessage();
                error_log('ChatModerationService Groq: ' . $e->getMessage());
            }
        }

        if (self::isOpenRouterConfigured()) {
            try {
                return $this->analyzeWithOpenRouter($text, $regexResult);
            } catch (Throwable $e) {
                $errors[] = 'OpenRouter: ' . $e->getMessage();
                error_log('ChatModerationService OpenRouter: ' . $e->getMessage());
            }
        }

        if (self::isGeminiConfigured()) {
            try {
                return $this->analyzeWithGemini($text, $regexResult);
            } catch (Throwable $e) {
                $errors[] = 'Gemini: ' . $e->getMessage();
                error_log('ChatModerationService Gemini: ' . $e->getMessage());
            }
        }

        throw new RuntimeException('AI providers failed: ' . implode(' | ', $errors));
    }

    private function analyzeWithGroq(string $text, array $regexResult): array {
        $model = defined('CHAT_MODERATION_GROQ_MODEL') ? CHAT_MODERATION_GROQ_MODEL : GroqService::defaultModel();
        $prompt = $this->buildChatModerationPrompt($text, $regexResult);
        $parsed = GroqService::chatJson($prompt, $model, 0.1);
        return $this->normalizeChatAiResult($parsed, $model, self::PROVIDER_GROQ);
    }

    private function analyzeWithOpenRouter(string $text, array $regexResult): array {
        $model = defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : OpenRouterService::defaultModel();
        $prompt = $this->buildChatModerationPrompt($text, $regexResult);
        $parsed = OpenRouterService::chatJson($prompt, $model, 0.1);
        return $this->normalizeChatAiResult($parsed, $model, self::PROVIDER_OPENROUTER);
    }

    private function analyzeWithGemini(string $text, array $regexResult): array {
        $model = defined('CHAT_MODERATION_GEMINI_MODEL') ? CHAT_MODERATION_GEMINI_MODEL : 'gemini-2.0-flash';
        $prompt = $this->buildChatModerationPrompt($text, $regexResult);

        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model)
            . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

        $response = $this->httpJson('POST', $url, $payload);
        $rawText = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if ($rawText === '') {
            $blockReason = $response['promptFeedback']['blockReason'] ?? 'empty response';
            throw new RuntimeException('Gemini empty response: ' . $blockReason);
        }

        $parsed = json_decode(trim($rawText), true);
        if (!is_array($parsed)) {
            throw new RuntimeException('Gemini returned invalid JSON');
        }

        return $this->normalizeChatAiResult($parsed, $model, self::PROVIDER_GEMINI);
    }

    private function buildChatModerationPrompt(string $text, array $regexResult): string {
        $regexHints = !empty($regexResult['matched_patterns'])
            ? implode(', ', $regexResult['matched_patterns']) : 'none';
        return <<<PROMPT
You moderate peer-to-peer chat on FLIXO Escrow, a Thai marketplace where buyers must pay through escrow.

Analyze the message for attempts to:
- share bank account / PromptPay for payment outside escrow
- share phone, Line ID, or social contact to move off-platform
- solicit direct transfer or payment outside the escrow system
- share external links for off-platform deals

Regex pre-scan hints: {$regexHints}

Return ONLY valid JSON (no markdown):
{
  "risk_score": <integer 0-100>,
  "verdict": "pass" | "flag" | "reject",
  "reasons": ["short reason in Thai, max 3 items"],
  "categories": ["bank_account"|"phone_contact"|"off_platform_transfer"|"external_link"|"none"]
}

Guidelines:
- "pass" = normal product discussion, escrow flow, or warnings NOT to pay outside
- "flag" = mildly suspicious or ambiguous
- "reject" = clear attempt to bypass escrow or share payment/contact off-platform
- Be strict on Thai slang/evasion (โอนมาเลย, คุยนอกแอพ, ลดให้ถ้าไม่ผ่านระบบ)

Message:
"""
{$text}
"""
PROMPT;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeChatAiResult(array $parsed, string $model, string $provider): array {
        $riskScore = max(0, min(100, (float)($parsed['risk_score'] ?? 0)));
        $verdict = $this->normalizeAiVerdict((string)($parsed['verdict'] ?? 'pass'), $riskScore);
        $reasons = $this->normalizeReasons($parsed['reasons'] ?? []);
        $categories = is_array($parsed['categories'] ?? null) ? $parsed['categories'] : [];
        $rawKey = match ($provider) {
            self::PROVIDER_OPENROUTER => 'openrouter',
            self::PROVIDER_GROQ => 'groq',
            default => 'gemini',
        };
        return [
            'provider' => $provider,
            'risk_score' => $riskScore,
            'verdict' => $verdict,
            'status' => $this->verdictToStatus($verdict),
            'matched_patterns' => array_values(array_filter($categories, static fn($c) => $c !== 'none')),
            'reasons' => $reasons,
            'raw' => [
                'tier' => 2,
                'model' => $model,
                $rawKey => $parsed,
            ],
        ];
    }

    private function normalizeAiVerdict(string $verdict, float $riskScore): string {
        $verdict = strtolower(trim($verdict));
        if (in_array($verdict, [self::VERDICT_PASS, self::VERDICT_FLAG, self::VERDICT_REJECT], true)) {
            return $this->resolveVerdict($riskScore, $verdict);
        }
        return $this->resolveVerdict($riskScore);
    }

    /**
     * @param array<string, mixed> $regexResult
     * @param array<string, mixed> $aiResult
     */
    private function mergeResults(array $regexResult, array $aiResult): array {
        $riskScore = max((float)$regexResult['risk_score'], (float)$aiResult['risk_score']);
        $verdict = $this->worseVerdict(
            (string)$regexResult['verdict'],
            (string)$aiResult['verdict']
        );
        $verdict = $this->resolveVerdict($riskScore, $verdict);

        $patterns = array_values(array_unique(array_merge(
            $regexResult['matched_patterns'] ?? [],
            $aiResult['matched_patterns'] ?? []
        )));
        if (!empty($aiResult['matched_patterns'])) {
            $patterns[] = 'ai_tier';
        }
        $patterns = array_values(array_unique($patterns));

        $reasons = array_values(array_unique(array_merge(
            $regexResult['reasons'] ?? [],
            $aiResult['reasons'] ?? []
        )));

        $aiProvider = (string)($aiResult['provider'] ?? self::PROVIDER_GEMINI);
        return [
            'provider' => self::PROVIDER_REGEX . '+' . $aiProvider,
            'risk_score' => $riskScore,
            'verdict' => $verdict,
            'status' => $this->verdictToStatus($verdict),
            'matched_patterns' => $patterns,
            'reasons' => array_slice($reasons, 0, 5),
            'raw' => [
                'tier' => 2,
                'regex' => $regexResult['raw'] ?? [],
                'ai' => $aiResult['raw'] ?? [],
            ],
        ];
    }

    private function worseVerdict(string $current, string $candidate): string {
        $rank = [self::VERDICT_PASS => 0, self::VERDICT_FLAG => 1, self::VERDICT_REJECT => 2];
        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    /**
     * @param mixed $reasons
     * @return string[]
     */
    private function normalizeReasons($reasons): array {
        if (!is_array($reasons)) {
            return [];
        }
        $out = [];
        foreach ($reasons as $reason) {
            $text = trim((string)$reason);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    private function normalizeText(string $text): string {
        return preg_replace('/[\s\-_.]/u', '', $text) ?? $text;
    }

    private function matchPhoneNumber(string $normalized, string $original): bool {
        if (preg_match('/\b0[689]\d{8}\b/u', $normalized)) {
            return true;
        }
        if (preg_match('/\+66[689]\d{8}\b/u', $normalized)) {
            return true;
        }
        if (preg_match('/\b0[689][\d\s\-]{7,14}\d\b/u', $original)) {
            $digits = preg_replace('/\D/u', '', $original);
            return (bool)preg_match('/0[689]\d{8}/', $digits ?? '');
        }
        return false;
    }

    private function matchBankAccount(string $normalized, string $original): bool {
        if ($this->matchPhoneNumber($normalized, $original)) {
            return false;
        }
        if (preg_match('/\d{10,12}/', $normalized)) {
            return true;
        }
        if (preg_match('/\b\d{3}[\s\-]?\d{1}[\s\-]?\d{5}[\s\-]?\d{1}\b/u', $original)) {
            return true;
        }
        return false;
    }

    private function matchExternalUrl(string $text): bool {
        $patterns = [
            '/https?:\/\/[^\s]+/iu',
            '/\bwww\.[^\s]+/iu',
            '/\b(?:t\.me|line\.me|wa\.me|bit\.ly|goo\.gl|fb\.com|facebook\.com|instagram\.com|m\.me)\/[^\s]*/iu',
            '/\b(?:telegram|discord)\.(?:me|gg)\/[^\s]*/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $keywords
     * @return string[]
     */
    private function matchKeywords(string $text, array $keywords): array {
        $lower = mb_strtolower($text);
        $found = [];
        foreach ($keywords as $keyword) {
            if (mb_strpos($lower, mb_strtolower($keyword)) !== false) {
                $found[] = $keyword;
            }
        }
        return $found;
    }

    private function matchLineId(string $text): bool {
        return (bool)preg_match('/\bline\s*[@:id]?\s*[a-z0-9._\-]{4,}/iu', $text)
            || (bool)preg_match('/@[a-z][a-z0-9._]{3,}/iu', $text);
    }

    private function matchDiscountBypassBait(string $text): bool {
        $lower = mb_strtolower($text);
        $hasDiscount = (bool)preg_match('/ลดราคา|ลดให้|ลดได้|ถูกกว่า|ไม่เอาค่าธรรมเนียม/ui', $lower);
        $hasBypass = (bool)preg_match('/โอนมา|โอนตรง|โอนนอก|ไม่ผ่าน|นอกแอพ|นอกแอป|นอกระบบ|ไม่ผ่านแอพ|ไม่ผ่านแอป/ui', $lower);
        return $hasDiscount && $hasBypass;
    }

    private function resolveVerdict(float $riskScore, ?string $aiVerdict = null): string {
        $blockThreshold = defined('CHAT_MODERATION_BLOCK_THRESHOLD')
            ? (float)CHAT_MODERATION_BLOCK_THRESHOLD : 70.0;
        $flagThreshold = defined('CHAT_MODERATION_FLAG_THRESHOLD')
            ? (float)CHAT_MODERATION_FLAG_THRESHOLD : 45.0;

        if ($riskScore >= $blockThreshold || $aiVerdict === self::VERDICT_REJECT) {
            return self::VERDICT_REJECT;
        }
        if ($riskScore >= $flagThreshold || $aiVerdict === self::VERDICT_FLAG) {
            return self::VERDICT_FLAG;
        }
        return self::VERDICT_PASS;
    }
    private function verdictToStatus(string $verdict): string {
        if ($verdict === self::VERDICT_REJECT) {
            return 'blocked';
        }
        if ($verdict === self::VERDICT_FLAG) {
            return 'flagged';
        }
        return 'passed';
    }

    private function verdictToModerationStatus(string $verdict): string {
        if ($verdict === self::VERDICT_REJECT) {
            return 'blocked';
        }
        if ($verdict === self::VERDICT_FLAG) {
            return 'flagged';
        }
        return 'clean';
    }

    private function skippedResult(): array {
        return [
            'provider' => self::PROVIDER_OFF,
            'risk_score' => 0,
            'verdict' => self::VERDICT_PASS,
            'status' => 'skipped',
            'matched_patterns' => [],
            'reasons' => [],
            'raw' => [],
        ];
    }

    private function extractTextWithCloudVision(string $absolutePath): string {
        $base64 = base64_encode((string)file_get_contents($absolutePath));
        $payload = [
            'requests' => [[
                'image' => ['content' => $base64],
                'features' => [
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 1],
                ],
            ]],
        ];

        $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode(GOOGLE_CLOUD_VISION_API_KEY);
        $response = $this->httpJson('POST', $url, $payload);
        $annotation = $response['responses'][0] ?? [];

        if (isset($annotation['error'])) {
            throw new RuntimeException($annotation['error']['message'] ?? 'Cloud Vision error');
        }

        foreach ($annotation['textAnnotations'] ?? [] as $i => $textAnn) {
            if ($i === 0) {
                return (string)($textAnn['description'] ?? '');
            }
        }
        return '';
    }

    private function httpJson(string $method, string $url, array $payload): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Vision API request failed: ' . $curlError);
        }

        $data = json_decode($resp, true);
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? $data['error'] ?? 'Vision API error';
            throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg));
        }

        return is_array($data) ? $data : [];
    }

    private function hasScansTable(): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW TABLES LIKE 'chat_moderation_scans'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }

    private function hasModerationColumns(): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW COLUMNS FROM messages LIKE 'moderation_status'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }
}
