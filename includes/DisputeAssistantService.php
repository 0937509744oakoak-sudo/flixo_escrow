<?php
// includes/DisputeAssistantService.php — AI analysis for dispute resolution

require_once __DIR__ . '/CourierService.php';
require_once __DIR__ . '/order_helper.php';
require_once __DIR__ . '/OpenRouterService.php';
require_once __DIR__ . '/GroqService.php';
require_once __DIR__ . '/MockApiHelper.php';

class DisputeAssistantService {
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_GROQ = 'groq';
    public const PROVIDER_HEURISTIC = 'heuristic';

    public const ACTION_REFUND = 'REFUND_BUYER';
    public const ACTION_PAYOUT = 'PAYOUT_SELLER';
    public const ACTION_SPLIT = 'SPLIT';
    public const ACTION_REVIEW = 'NEEDS_REVIEW';

    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public static function isEnabled(): bool {
        return !defined('DISPUTE_AI_ENABLED') || DISPUTE_AI_ENABLED;
    }

    public static function isGeminiConfigured(): bool {
        if (MockApiHelper::isEnabled()) {
            return true;
        }
        return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
    }

    public static function isOpenRouterConfigured(): bool {
        if (MockApiHelper::isEnabled()) {
            return true;
        }
        return OpenRouterService::isConfigured();
    }

    public static function isGroqConfigured(): bool {
        if (MockApiHelper::isEnabled()) {
            return true;
        }
        return GroqService::isConfigured();
    }

    public static function isAiConfigured(): bool {
        return self::isGroqConfigured() || self::isOpenRouterConfigured() || self::isGeminiConfigured();
    }

    public function analyze(int $disputeId, string $requestedBy, bool $force = false): array {
        if (!self::isEnabled()) {
            return $this->errorResult('ระบบ AI Dispute Assistant ถูกปิดใช้งาน');
        }

        $context = $this->buildContext($disputeId);
        if ($context === null) {
            return $this->errorResult('ไม่พบข้อพิพาท');
        }

        if (!$force) {
            $cached = $this->getLatestAnalysis($disputeId);
            if ($cached && $cached['status'] === 'completed') {
                $cachedAt = strtotime($cached['created_at'] ?? '');
                $disputeAt = strtotime($context['dispute']['created_at'] ?? '');
                if ($cachedAt >= $disputeAt && (time() - $cachedAt) < 3600) {
                    return $this->formatStoredAnalysis($cached);
                }
            }
        }

        try {
            if (self::isAiConfigured()) {
                try {
                    $result = $this->analyzeWithPreferredAi($context);
                } catch (Throwable $aiError) {
                    error_log('DisputeAssistantService AI fallback: ' . $aiError->getMessage());
                    $result = $this->analyzeWithHeuristics($context);
                    $findings = $result['key_findings'] ?? [];
                    array_unshift($findings, 'AI ไม่พร้อม — ใช้กฎ heuristic แทน');
                    $result['key_findings'] = $findings;
                }
            } else {
                $result = $this->analyzeWithHeuristics($context);
            }

            $analysisId = $this->saveAnalysis($disputeId, $requestedBy, $result);
            $result['analysis_id'] = $analysisId;
            $result['status'] = 'completed';
            return $result;
        } catch (Throwable $e) {
            error_log('DisputeAssistantService: ' . $e->getMessage());
            $errorResult = $this->errorResult('วิเคราะห์ไม่สำเร็จ: ' . $e->getMessage());
            $this->saveAnalysis($disputeId, $requestedBy, $errorResult);
            return $errorResult;
        }
    }

    public function getLatestFormatted(int $disputeId): ?array {
        $row = $this->getLatestAnalysis($disputeId);
        if (!$row || ($row['status'] ?? '') !== 'completed') {
            return null;
        }
        return $this->formatStoredAnalysis($row);
    }

    public function getLatestAnalysis(int $disputeId): ?array {
        if (!$this->hasAnalysesTable()) {
            return null;
        }
        $stmt = $this->conn->prepare(
            "SELECT * FROM dispute_ai_analyses WHERE dispute_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->bind_param('i', $disputeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildContext(int $disputeId): ?array {
        $stmt = $this->conn->prepare(
            "SELECT d.*, o.id AS order_id, o.item_name, o.amount, o.status AS order_status,
                    o.buyer_name, o.seller_name, o.tracking_number, o.courier, o.courier_code,
                    o.tracking_status, o.tracking_status_code, o.shipped_at, o.created_at AS order_created_at
             FROM disputes d
             JOIN orders o ON d.order_id = o.id
             WHERE d.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $disputeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }

        $orderId = (int)$row['order_id'];
        $buyer = $row['buyer_name'];
        $seller = $row['seller_name'];

        return [
            'dispute' => $row,
            'order' => $row,
            'chat' => $this->getChatMessages($buyer, $seller),
            'chat_flags' => $this->getChatModerationFlags($buyer, $seller),
            'shipment' => (new CourierService($this->conn))->getTimeline($orderId),
            'evidences' => $this->getEvidences($disputeId),
            'comments' => $this->getComments($disputeId),
            'status_logs' => $this->getOrderStatusLogs($orderId),
        ];
    }

    private function getChatMessages(string $buyer, string $seller, int $limit = 80): array {
        $stmt = $this->conn->prepare(
            "SELECT sender_name, receiver_name, message, image_path, moderation_status, moderation_score,
                    moderation_reasons, created_at
             FROM messages
             WHERE (sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?)
             ORDER BY created_at ASC
             LIMIT ?"
        );
        $stmt->bind_param('ssssi', $buyer, $seller, $seller, $buyer, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return array_map(static function (array $msg): array {
            $text = trim($msg['message'] ?? '');
            if ($text === '' && !empty($msg['image_path'])) {
                $text = '[ส่งรูปภาพ]';
            }
            if (strlen($text) > 500) {
                $text = mb_substr($text, 0, 500) . '…';
            }
            return [
                'from' => $msg['sender_name'],
                'to' => $msg['receiver_name'],
                'text' => $text,
                'at' => $msg['created_at'],
                'moderation' => $msg['moderation_status'] ?? null,
                'moderation_score' => $msg['moderation_score'] ?? null,
            ];
        }, $rows);
    }

    private function getChatModerationFlags(string $buyer, string $seller): array {
        if (!$this->hasModerationColumns()) {
            return [];
        }
        $stmt = $this->conn->prepare(
            "SELECT sender_name, message, moderation_status, moderation_score, moderation_reasons, created_at
             FROM messages
             WHERE ((sender_name = ? AND receiver_name = ?) OR (sender_name = ? AND receiver_name = ?))
               AND moderation_status IN ('flagged', 'blocked')
             ORDER BY created_at DESC
             LIMIT 20"
        );
        $stmt->bind_param('ssss', $buyer, $seller, $seller, $buyer);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getEvidences(int $disputeId): array {
        $stmt = $this->conn->prepare(
            "SELECT uploader, description, file_path, created_at FROM dispute_evidences WHERE dispute_id = ? ORDER BY created_at ASC"
        );
        $stmt->bind_param('i', $disputeId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getComments(int $disputeId): array {
        $stmt = $this->conn->prepare(
            "SELECT commenter, comment, created_at FROM dispute_comments WHERE dispute_id = ? ORDER BY created_at ASC"
        );
        $stmt->bind_param('i', $disputeId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getOrderStatusLogs(int $orderId): array {
        $stmt = $this->conn->prepare(
            "SELECT old_status, new_status, changed_by, reason, created_at FROM order_status_logs WHERE order_id = ? ORDER BY created_at ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Prefer Groq → OpenRouter → Gemini.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    /**
     * Benchmark / load-test entry (no DB dispute required, no persistence).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function analyzeContextForBenchmark(array $context, bool $forceHeuristic = false): array {
        $amount = (float) ($context['order']['amount'] ?? 1000);
        if ($forceHeuristic) {
            return $this->analyzeWithHeuristics($context);
        }
        if (MockApiHelper::isEnabled()) {
            $forced = MockApiHelper::consumeDisputeAction();
            $parsed = MockApiHelper::disputeAiJson($amount, $forced);
            return $this->normalizeAnalysis($parsed, $amount, self::PROVIDER_GROQ, $parsed);
        }
        return $this->analyzeWithPreferredAi($context);
    }

    private function analyzeWithPreferredAi(array $context): array {
        if (MockApiHelper::isEnabled()) {
            $amount = (float) ($context['order']['amount'] ?? 1000);
            $parsed = MockApiHelper::disputeAiJson($amount, MockApiHelper::consumeDisputeAction());
            return $this->normalizeAnalysis($parsed, $amount, self::PROVIDER_GROQ, $parsed);
        }

        $errors = [];

        if (self::isGroqConfigured()) {
            try {
                return $this->analyzeWithGroq($context);
            } catch (Throwable $e) {
                $errors[] = 'Groq: ' . $e->getMessage();
                error_log('DisputeAssistantService Groq: ' . $e->getMessage());
            }
        }

        if (self::isOpenRouterConfigured()) {
            try {
                return $this->analyzeWithOpenRouter($context);
            } catch (Throwable $e) {
                $errors[] = 'OpenRouter: ' . $e->getMessage();
                error_log('DisputeAssistantService OpenRouter: ' . $e->getMessage());
            }
        }

        if (self::isGeminiConfigured()) {
            try {
                return $this->analyzeWithGemini($context);
            } catch (Throwable $e) {
                $errors[] = 'Gemini: ' . $e->getMessage();
                error_log('DisputeAssistantService Gemini: ' . $e->getMessage());
            }
        }

        throw new RuntimeException('AI providers failed: ' . implode(' | ', $errors));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analyzeWithGroq(array $context): array {
        $model = defined('DISPUTE_AI_GROQ_MODEL') ? DISPUTE_AI_GROQ_MODEL : GroqService::defaultModel();
        $prompt = $this->buildDisputeAnalysisPrompt($context);
        $parsed = GroqService::chatJson($prompt, $model, 0.2);
        return $this->normalizeAnalysis($parsed, (float) $context['order']['amount'], self::PROVIDER_GROQ, $parsed);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analyzeWithOpenRouter(array $context): array {
        $model = defined('DISPUTE_AI_MODEL') ? DISPUTE_AI_MODEL : OpenRouterService::defaultModel();
        $prompt = $this->buildDisputeAnalysisPrompt($context);
        $parsed = OpenRouterService::chatJson($prompt, $model, 0.2);
        return $this->normalizeAnalysis($parsed, (float)$context['order']['amount'], self::PROVIDER_OPENROUTER, $parsed);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analyzeWithGemini(array $context): array {
        $model = defined('DISPUTE_AI_GEMINI_MODEL') ? DISPUTE_AI_GEMINI_MODEL : 'gemini-2.0-flash';
        $prompt = $this->buildDisputeAnalysisPrompt($context);

        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.2,
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

        return $this->normalizeAnalysis($parsed, (float)$context['order']['amount'], self::PROVIDER_GEMINI, $parsed);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildDisputeAnalysisPrompt(array $context): string {
        $payloadText = $this->buildPromptPayload($context);
        return <<<PROMPT
You are an AI dispute assistant for FLIXO Escrow, a Thai C2C marketplace.
Analyze the dispute case and help admin/support decide fairly.

IMPORTANT:
- Money is held in escrow until resolved
- Admin makes final decision; your output is advisory only
- Consider Thai marketplace context and dispute reasons
- Weight: chat history, shipping/tracking status, evidence, dispute reason

Return ONLY valid JSON (no markdown):
{
  "summary": "2-3 sentence Thai summary of the case",
  "buyer_fault_pct": <integer 0-100>,
  "seller_fault_pct": <integer 0-100>,
  "recommended_action": "REFUND_BUYER" | "PAYOUT_SELLER" | "SPLIT" | "NEEDS_REVIEW",
  "suggested_buyer_refund": <number, baht, 0 if payout seller>,
  "suggested_seller_payout": <number, baht, 0 if refund buyer>,
  "confidence": <integer 0-100>,
  "key_findings": ["finding in Thai, max 5 items"],
  "reasoning": "brief Thai explanation of recommendation"
}

Rules:
- buyer_fault_pct + seller_fault_pct should equal 100
- For SPLIT: suggested_buyer_refund + suggested_seller_payout must equal order amount
- REFUND_BUYER: item not received, wrong item, seller fault, no valid tracking
- PAYOUT_SELLER: delivered with proof, buyer unreasonable, buyer tried off-platform scam
- NEEDS_REVIEW: insufficient data or conflicting evidence
- Be cautious if chat shows off-platform payment attempts

Case data:
{$payloadText}
PROMPT;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function analyzeWithHeuristics(array $context): array {
        $order = $context['order'];
        $dispute = $context['dispute'];
        $amount = (float)$order['amount'];
        $buyerFault = 50;
        $sellerFault = 50;
        $findings = [];
        $action = self::ACTION_REVIEW;
        $confidence = 45;

        $trackingCode = strtoupper($order['tracking_status_code'] ?? '');
        $hasTracking = !empty($order['tracking_number']);
        $reason = mb_strtolower($dispute['reason'] ?? '');
        $description = mb_strtolower($dispute['description'] ?? '');

        if (!$hasTracking) {
            $sellerFault = 70;
            $buyerFault = 30;
            $findings[] = 'ไม่พบเลขพัสดุ/การจัดส่งในระบบ';
            $action = self::ACTION_REFUND;
            $confidence = 60;
        } elseif (in_array($trackingCode, ['DELIVERED', 'delivered'], true)) {
            $sellerFault = 25;
            $buyerFault = 75;
            $findings[] = 'สถานะขนส่งแสดงว่าจัดส่งแล้ว (' . ($order['tracking_status'] ?? $trackingCode) . ')';
            $action = self::ACTION_PAYOUT;
            $confidence = 65;
        } elseif ($hasTracking) {
            $findings[] = 'มีเลขพัสดุ: ' . $order['tracking_number'] . ' (' . ($order['courier'] ?? '-') . ')';
            $sellerFault = 40;
            $buyerFault = 60;
        }

        $notReceivedKeywords = ['ไม่ได้รับ', 'ไม่มา', 'ไม่ส่ง', 'ไม่มีของ', 'not received', 'ยังไม่ได้'];
        foreach ($notReceivedKeywords as $kw) {
            if (mb_strpos($reason, $kw) !== false || mb_strpos($description, $kw) !== false) {
                $buyerFault = min(80, $buyerFault + 15);
                $sellerFault = 100 - $buyerFault;
                $findings[] = 'ผู้ซื้อแจ้งว่าไม่ได้รับสินค้า';
                if (!$hasTracking || !in_array($trackingCode, ['DELIVERED', 'delivered'], true)) {
                    $action = self::ACTION_REFUND;
                    $confidence = max($confidence, 55);
                }
                break;
            }
        }

        $wrongItemKeywords = ['ไม่ตรง', 'ผิด', 'ชำรุด', 'เสีย', 'ของปลอม', 'ไม่ครบ'];
        foreach ($wrongItemKeywords as $kw) {
            if (mb_strpos($reason, $kw) !== false || mb_strpos($description, $kw) !== false) {
                $findings[] = 'ผู้ซื้อแจ้งสินค้าไม่ตรงปก/มีปัญหา';
                if (count($context['evidences']) > 0) {
                    $action = self::ACTION_SPLIT;
                    $buyerFault = 60;
                    $sellerFault = 40;
                    $confidence = 50;
                } else {
                    $action = self::ACTION_REVIEW;
                    $confidence = 40;
                }
                break;
            }
        }

        if (!empty($context['chat_flags'])) {
            $findings[] = 'พบข้อความเสี่ยงในแชท (' . count($context['chat_flags']) . ' รายการ)';
            $confidence = max(30, $confidence - 10);
            if ($action === self::ACTION_PAYOUT) {
                $action = self::ACTION_REVIEW;
            }
        }

        if (count($context['evidences']) === 0) {
            $findings[] = 'ยังไม่มีหลักฐานแนบในข้อพิพาท';
            $confidence = max(30, $confidence - 15);
        } else {
            $findings[] = 'มีหลักฐานแนบ ' . count($context['evidences']) . ' รายการ';
        }

        $buyerRefund = 0.0;
        $sellerPayout = 0.0;
        if ($action === self::ACTION_REFUND) {
            $buyerRefund = $amount;
        } elseif ($action === self::ACTION_PAYOUT) {
            $sellerPayout = $amount;
        } elseif ($action === self::ACTION_SPLIT) {
            $buyerRefund = round($amount * ($buyerFault / 100), 2);
            $sellerPayout = round($amount - $buyerRefund, 2);
        }

        $summary = 'วิเคราะห์ด้วยกฎเบื้องต้น (ไม่มี AI API) — '
            . 'ผู้ซื้อ ' . $buyerFault . '% / ผู้ขาย ' . $sellerFault . '%';

        return [
            'provider' => self::PROVIDER_HEURISTIC,
            'summary' => $summary,
            'buyer_fault_pct' => $buyerFault,
            'seller_fault_pct' => $sellerFault,
            'recommended_action' => $action,
            'suggested_buyer_refund' => $buyerRefund,
            'suggested_seller_payout' => $sellerPayout,
            'confidence' => $confidence,
            'key_findings' => $findings,
            'reasoning' => 'ใช้กฎ heuristic จากสถานะขนส่ง, เหตุผลข้อพิพาท, หลักฐาน และแชท — แนะนำให้ admin ตรวจสอบเพิ่มเติม',
            'raw' => ['heuristic' => true],
        ];
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeAnalysis(array $parsed, float $orderAmount, string $provider, ?array $raw = null): array {
        $buyerFault = max(0, min(100, (int)($parsed['buyer_fault_pct'] ?? 50)));
        $sellerFault = max(0, min(100, (int)($parsed['seller_fault_pct'] ?? (100 - $buyerFault))));
        if ($buyerFault + $sellerFault !== 100) {
            $sellerFault = 100 - $buyerFault;
        }

        $action = strtoupper(trim((string)($parsed['recommended_action'] ?? self::ACTION_REVIEW)));
        $allowed = [self::ACTION_REFUND, self::ACTION_PAYOUT, self::ACTION_SPLIT, self::ACTION_REVIEW];
        if (!in_array($action, $allowed, true)) {
            $action = self::ACTION_REVIEW;
        }

        $buyerRefund = max(0, (float)($parsed['suggested_buyer_refund'] ?? 0));
        $sellerPayout = max(0, (float)($parsed['suggested_seller_payout'] ?? 0));

        if ($action === self::ACTION_REFUND) {
            $buyerRefund = $orderAmount;
            $sellerPayout = 0;
        } elseif ($action === self::ACTION_PAYOUT) {
            $buyerRefund = 0;
            $sellerPayout = $orderAmount;
        } elseif ($action === self::ACTION_SPLIT && ($buyerRefund + $sellerPayout) <= 0) {
            $buyerRefund = round($orderAmount * ($buyerFault / 100), 2);
            $sellerPayout = round($orderAmount - $buyerRefund, 2);
        }

        $findings = $parsed['key_findings'] ?? [];
        if (!is_array($findings)) {
            $findings = [];
        }
        $findings = array_values(array_filter(array_map('strval', array_slice($findings, 0, 5))));

        return [
            'provider' => $provider,
            'summary' => trim((string)($parsed['summary'] ?? '')),
            'buyer_fault_pct' => $buyerFault,
            'seller_fault_pct' => $sellerFault,
            'recommended_action' => $action,
            'suggested_buyer_refund' => $buyerRefund,
            'suggested_seller_payout' => $sellerPayout,
            'confidence' => max(0, min(100, (int)($parsed['confidence'] ?? 50))),
            'key_findings' => $findings,
            'reasoning' => trim((string)($parsed['reasoning'] ?? '')),
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildPromptPayload(array $context): string {
        $order = $context['order'];
        $dispute = $context['dispute'];

        $lines = [
            '=== ORDER ===',
            'Order ID: ' . $order['order_id'],
            'Item: ' . $order['item_name'],
            'Amount: ' . number_format((float)$order['amount'], 2) . ' THB',
            'Status: ' . $order['order_status'],
            'Buyer: ' . $order['buyer_name'],
            'Seller: ' . $order['seller_name'],
            '',
            '=== DISPUTE ===',
            'Reason: ' . $dispute['reason'],
            'Description: ' . ($dispute['description'] ?? '-'),
            'Initiator: ' . $dispute['initiator'],
            'Status: ' . $dispute['status'],
            '',
            '=== SHIPPING ===',
            'Courier: ' . ($order['courier'] ?? '-'),
            'Tracking: ' . ($order['tracking_number'] ?? '-'),
            'Tracking status: ' . ($order['tracking_status'] ?? '-') . ' (' . ($order['tracking_status_code'] ?? '-') . ')',
        ];

        if (!empty($context['shipment'])) {
            $lines[] = 'Timeline:';
            foreach ($context['shipment'] as $ev) {
                $lines[] = '  - [' . $ev['event_at'] . '] ' . $ev['status_label'] . ' (' . $ev['status_code'] . ')';
            }
        }

        if (!empty($context['evidences'])) {
            $lines[] = '';
            $lines[] = '=== EVIDENCE (' . count($context['evidences']) . ') ===';
            foreach ($context['evidences'] as $ev) {
                $lines[] = '- ' . $ev['uploader'] . ': ' . ($ev['description'] ?: basename($ev['file_path'])) . ' (' . $ev['created_at'] . ')';
            }
        }

        if (!empty($context['comments'])) {
            $lines[] = '';
            $lines[] = '=== DISPUTE COMMENTS ===';
            foreach ($context['comments'] as $c) {
                $lines[] = '[' . $c['created_at'] . '] ' . $c['commenter'] . ': ' . mb_substr($c['comment'], 0, 300);
            }
        }

        if (!empty($context['chat_flags'])) {
            $lines[] = '';
            $lines[] = '=== FLAGGED CHAT MESSAGES ===';
            foreach ($context['chat_flags'] as $f) {
                $lines[] = '[' . $f['created_at'] . '] ' . $f['sender_name'] . ' (' . $f['moderation_status'] . '): '
                    . mb_substr($f['message'] ?? '[image]', 0, 200);
            }
        }

        if (!empty($context['chat'])) {
            $lines[] = '';
            $lines[] = '=== CHAT HISTORY (buyer/seller) ===';
            foreach ($context['chat'] as $msg) {
                $mod = $msg['moderation'] ? ' [' . $msg['moderation'] . ']' : '';
                $lines[] = '[' . $msg['at'] . '] ' . $msg['from'] . ' → ' . $msg['to'] . $mod . ': ' . $msg['text'];
            }
        }

        if (!empty($context['status_logs'])) {
            $lines[] = '';
            $lines[] = '=== ORDER STATUS LOG ===';
            foreach ($context['status_logs'] as $log) {
                $lines[] = '[' . $log['created_at'] . '] ' . ($log['old_status'] ?: '-') . ' → ' . $log['new_status']
                    . ' by ' . ($log['changed_by'] ?? '') . ': ' . ($log['reason'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function saveAnalysis(int $disputeId, string $requestedBy, array $result): int {
        if (!$this->hasAnalysesTable()) {
            return 0;
        }

        $status = ($result['status'] ?? 'completed') === 'error' ? 'error' : 'completed';
        $provider = $result['provider'] ?? self::PROVIDER_HEURISTIC;
        $summary = $result['summary'] ?? null;
        $buyerFault = isset($result['buyer_fault_pct']) ? (int)$result['buyer_fault_pct'] : null;
        $sellerFault = isset($result['seller_fault_pct']) ? (int)$result['seller_fault_pct'] : null;
        $action = $result['recommended_action'] ?? null;
        $buyerRefund = $result['suggested_buyer_refund'] ?? null;
        $sellerPayout = $result['suggested_seller_payout'] ?? null;
        if ($buyerRefund === null) {
            $buyerRefund = 0.0;
        }
        if ($sellerPayout === null) {
            $sellerPayout = 0.0;
        }
        $confidence = isset($result['confidence']) ? (int)$result['confidence'] : null;
        $findingsJson = !empty($result['key_findings']) ? json_encode($result['key_findings'], JSON_UNESCAPED_UNICODE) : null;
        $reasoning = $result['reasoning'] ?? null;
        $rawJson = !empty($result['raw']) ? json_encode($result['raw'], JSON_UNESCAPED_UNICODE) : null;
        $errorMsg = $result['error_message'] ?? null;

        $stmt = $this->conn->prepare(
            "INSERT INTO dispute_ai_analyses
             (dispute_id, requested_by, provider, summary, buyer_fault_pct, seller_fault_pct,
              recommended_action, suggested_buyer_refund, suggested_seller_payout, confidence,
              key_findings, reasoning, raw_response, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isssiisddisssss',
            $disputeId,
            $requestedBy,
            $provider,
            $summary,
            $buyerFault,
            $sellerFault,
            $action,
            $buyerRefund,
            $sellerPayout,
            $confidence,
            $findingsJson,
            $reasoning,
            $rawJson,
            $status,
            $errorMsg
        );
        $stmt->execute();
        return (int)$this->conn->insert_id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatStoredAnalysis(array $row): array {
        $findings = [];
        if (!empty($row['key_findings'])) {
            $decoded = json_decode($row['key_findings'], true);
            $findings = is_array($decoded) ? $decoded : [];
        }

        return [
            'status' => $row['status'],
            'analysis_id' => (int)$row['id'],
            'provider' => $row['provider'],
            'summary' => $row['summary'],
            'buyer_fault_pct' => (int)$row['buyer_fault_pct'],
            'seller_fault_pct' => (int)$row['seller_fault_pct'],
            'recommended_action' => $row['recommended_action'],
            'suggested_buyer_refund' => (float)$row['suggested_buyer_refund'],
            'suggested_seller_payout' => (float)$row['suggested_seller_payout'],
            'confidence' => (int)$row['confidence'],
            'key_findings' => $findings,
            'reasoning' => $row['reasoning'],
            'created_at' => $row['created_at'],
            'cached' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $message): array {
        return [
            'status' => 'error',
            'provider' => self::PROVIDER_HEURISTIC,
            'error_message' => $message,
            'summary' => null,
            'key_findings' => [],
        ];
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
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Gemini API request failed: ' . $curlError);
        }

        $data = json_decode($resp, true);
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? $data['error'] ?? 'Gemini API error';
            throw new RuntimeException(is_string($msg) ? $msg : json_encode($msg));
        }

        return is_array($data) ? $data : [];
    }

    private function hasAnalysesTable(): bool {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $result = $this->conn->query("SHOW TABLES LIKE 'dispute_ai_analyses'");
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

    public static function actionLabel(string $action): string {
        return match ($action) {
            self::ACTION_REFUND => 'คืนเงินผู้ซื้อ',
            self::ACTION_PAYOUT => 'โอนให้ผู้ขาย',
            self::ACTION_SPLIT => 'แบ่งเงิน',
            default => 'ต้องตรวจสอบเพิ่ม',
        };
    }

    public static function actionBadgeClass(string $action): string {
        return match ($action) {
            self::ACTION_REFUND => 'ai-rec-refund',
            self::ACTION_PAYOUT => 'ai-rec-payout',
            self::ACTION_SPLIT => 'ai-rec-split',
            default => 'ai-rec-review',
        };
    }
}
