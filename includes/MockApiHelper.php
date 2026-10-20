<?php
// includes/MockApiHelper.php — Shared mock responses for load testing without burning API quota

class MockApiHelper {
    /** @var bool|null Force next OCR success (true) / fail (false); null = random */
    private static ?bool $forceOcrSuccess = null;

    /** @var bool|null Force next face match; null = random */
    private static ?bool $forceFaceMatch = null;

    /** @var bool When true, skip random FACE_NOT_DETECTED / OCR fail noise */
    private static bool $deterministicScenario = false;

    public static function isEnabled(): bool {
        if (defined('MOCK_API_MODE')) {
            return (bool) MOCK_API_MODE;
        }
        $raw = strtolower(trim((string) (getenv('MOCK_API_MODE') ?: '')));
        return $raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on';
    }

    /**
     * Drive the next AiGen mock responses for Ground-Truth load tests.
     * Call clearScenario() after each run (also auto-cleared after consume).
     */
    public static function setEkycScenario(bool $genuineMatch, bool $ocrSuccess = true): void {
        self::$deterministicScenario = true;
        self::$forceOcrSuccess = $ocrSuccess;
        self::$forceFaceMatch = $genuineMatch;
    }

    public static function clearScenario(): void {
        self::$forceOcrSuccess = null;
        self::$forceFaceMatch = null;
        self::$deterministicScenario = false;
        self::$imageFraudScenario = null;
        self::$disputeAction = null;
    }

    /** @var string|null 'clean' | 'risky' for Product Image Fraud GT tests */
    private static ?string $imageFraudScenario = null;

    /** @var string|null Force dispute recommended_action for GT tests */
    private static ?string $disputeAction = null;

    public static function setImageFraudScenario(string $scenario): void {
        $scenario = strtolower(trim($scenario));
        self::$imageFraudScenario = in_array($scenario, ['clean', 'risky'], true) ? $scenario : null;
    }

    public static function consumeImageFraudScenario(): ?string {
        $s = self::$imageFraudScenario;
        self::$imageFraudScenario = null;
        return $s;
    }

    public static function setDisputeAction(string $action): void {
        self::$disputeAction = strtoupper(trim($action));
    }

    public static function consumeDisputeAction(): ?string {
        $a = self::$disputeAction;
        self::$disputeAction = null;
        return $a;
    }

    /**
     * Simulate network + model latency (default 500–2000 ms).
     */
    public static function simulateLatency(int $minMs = 500, int $maxMs = 2000): void {
        if ($minMs < 0) {
            $minMs = 0;
        }
        if ($maxMs < $minMs) {
            $maxMs = $minMs;
        }
        usleep(random_int($minMs, $maxMs) * 1000);
    }

    /**
     * AiGen OCR — shape expected by EkycService::verifyWithAigen().
     *
     * @return array<string, mixed>
     */
    public static function aigenIdCardOcr(): array {
        self::simulateLatency();

        $forced = self::$forceOcrSuccess;
        self::$forceOcrSuccess = null;

        $shouldFail = $forced === false
            || ($forced === null && !self::$deterministicScenario && random_int(1, 100) <= 8);

        if ($shouldFail) {
            return [
                'status' => 'failed',
                'message' => 'ID_CARD_NOT_DETECTED',
                'data' => [],
                'mock' => true,
            ];
        }

        $id = (string) random_int(1000000000000, 9999999999999);
        return [
            'status' => 'success',
            'data' => [[
                'id_number' => [
                    'value' => $id,
                    'confidence' => round(random_int(850, 990) / 10, 1),
                ],
                'name_th' => [
                    'value' => 'สมชาย',
                    'confidence' => round(random_int(880, 995) / 10, 1),
                ],
                'surname_th' => [
                    'value' => 'ใจดี',
                    'confidence' => round(random_int(880, 995) / 10, 1),
                ],
                'title_name_surname_th' => [
                    'value' => 'นาย สมชาย ใจดี',
                    'confidence' => round(random_int(880, 995) / 10, 1),
                ],
                'face' => [
                    'detected' => true,
                    'confidence' => round(random_int(800, 980) / 10, 1),
                ],
            ]],
            'mock' => true,
        ];
    }

    /**
     * AiGen Face Compare — uses request threshold when present.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function aigenFaceCompare(array $payload = []): array {
        self::simulateLatency();

        $forcedMatch = self::$forceFaceMatch;
        self::$forceFaceMatch = null;

        // ~5% face-not-detected errors (skipped in deterministic GT scenarios)
        if ($forcedMatch === null && !self::$deterministicScenario && random_int(1, 100) <= 5) {
            return [
                'error_message' => 'FACE_NOT_DETECTED',
                'match' => false,
                'score' => null,
                'mock' => true,
            ];
        }

        $threshold = isset($payload['threshold']) ? (float) $payload['threshold'] : 80.0;
        if ($forcedMatch !== null) {
            $isMatch = $forcedMatch;
        } else {
            // ~72% genuine match above threshold
            $isMatch = random_int(1, 100) <= 72;
        }

        if ($isMatch) {
            $score = round($threshold + random_int(0, (int) max(1, 100 - $threshold)) + (random_int(0, 99) / 100), 2);
            $score = min(99.9, max($threshold, $score));
        } else {
            $score = round(max(10.0, $threshold - random_int(5, 45) - (random_int(0, 99) / 100)), 2);
        }

        self::$deterministicScenario = false;

        return [
            'match' => $isMatch,
            'score' => $score,
            'threshold' => $threshold,
            'error_message' => '',
            'mock' => true,
        ];
    }

    /**
     * Sumsub API mock by path.
     *
     * @return array<string, mixed>
     */
    public static function sumsubResponse(string $method, string $path): array {
        self::simulateLatency(400, 1600);

        if (str_contains($path, '/resources/accessTokens/sdk')) {
            return [
                'token' => 'mock_sumsub_sdk_' . bin2hex(random_bytes(16)),
                'userId' => 'flixo_mock',
                'mock' => true,
            ];
        }

        if (str_contains($path, '/resources/applicants') && strtoupper($method) === 'POST') {
            return [
                'id' => 'mock_applicant_' . bin2hex(random_bytes(8)),
                'createdAt' => gmdate('c'),
                'inspectionId' => 'mock_insp_' . bin2hex(random_bytes(6)),
                'externalUserId' => 'flixo_mock',
                'mock' => true,
            ];
        }

        // GET applicant review
        $roll = random_int(1, 100);
        if ($roll <= 55) {
            $status = 'completed';
            $answer = 'GREEN';
        } elseif ($roll <= 80) {
            $status = 'completed';
            $answer = 'RED';
        } else {
            $status = 'pending';
            $answer = '';
        }

        return [
            'id' => 'mock_applicant_' . bin2hex(random_bytes(8)),
            'externalUserId' => 'flixo_mock',
            'review' => [
                'reviewStatus' => $status,
                'reviewResult' => $answer !== '' ? ['reviewAnswer' => $answer] : [],
            ],
            'mock' => true,
        ];
    }

    /**
     * Google Cloud Vision images:annotate mock (fraud / OCR).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function cloudVisionAnnotate(array $payload = []): array {
        self::simulateLatency();

        $features = $payload['requests'][0]['features'] ?? [];
        $types = [];
        foreach ($features as $f) {
            if (is_array($f) && isset($f['type'])) {
                $types[] = (string) $f['type'];
            }
        }

        $response = [];
        $forcedFraud = self::consumeImageFraudScenario();

        if (in_array('SAFE_SEARCH_DETECTION', $types, true)
            || in_array('WEB_DETECTION', $types, true)
            || in_array('LABEL_DETECTION', $types, true)
        ) {
            if ($forcedFraud === 'clean') {
                $risky = false;
            } elseif ($forcedFraud === 'risky') {
                $risky = true;
            } else {
                $risky = random_int(1, 100) <= 35;
            }
            $response['safeSearchAnnotation'] = [
                'adult' => 'VERY_UNLIKELY',
                'spoof' => 'UNLIKELY',
                'medical' => 'UNLIKELY',
                'violence' => 'VERY_UNLIKELY',
                'racy' => $risky && random_int(0, 1) === 1 ? 'POSSIBLE' : 'VERY_UNLIKELY',
            ];
            $response['webDetection'] = [
                'fullMatchingImages' => $risky && random_int(1, 100) <= 40
                    ? [['url' => 'https://example.com/mock-listing.jpg']]
                    : [],
                'partialMatchingImages' => $risky && random_int(1, 100) <= 50
                    ? [['url' => 'https://example.com/similar.jpg']]
                    : [],
                'pagesWithMatchingImages' => $risky && random_int(1, 100) <= 45
                    ? [['url' => 'https://shopee.co.th/mock-product']]
                    : [],
            ];
            $response['labelAnnotations'] = $risky
                ? [
                    ['description' => 'Screenshot', 'score' => 0.82],
                    ['description' => 'Mobile phone', 'score' => 0.71],
                ]
                : [
                    ['description' => 'Product', 'score' => 0.91],
                    ['description' => 'Electronics', 'score' => 0.77],
                ];
            $response['textAnnotations'] = [
                ['description' => $risky ? "Shopee\nราคาพิเศษ\nLine: mockline01" : 'สินค้ามือสอง สภาพดี'],
            ];
        } elseif (in_array('TEXT_DETECTION', $types, true)) {
            $riskyText = random_int(1, 100) <= 40;
            $response['textAnnotations'] = [
                [
                    'description' => $riskyText
                        ? "โอนนอกระบบได้\nพร้อมเพย์ 0812345678\nแอดไลน์ mockline99"
                        : "คุยเรื่องสินค้าได้เลยครับ ชำระผ่าน FLIXO นะครับ",
                ],
            ];
        }

        return [
            'responses' => [$response],
            'mock' => true,
        ];
    }

    /**
     * Chat moderation LLM JSON (pre-normalize).
     *
     * @param array<string, mixed> $regexResult
     * @return array<string, mixed>
     */
    public static function chatModerationAiJson(string $text, array $regexResult = []): array {
        self::simulateLatency(80, 220); // keep mock ablation responsive

        $base = (float) ($regexResult['risk_score'] ?? 0);
        $jitter = random_int(-8, 18);
        $score = (int) max(0, min(100, round($base + $jitter)));

        // Soft-cue awareness (mirrors production soft-trigger intent under MOCK_API_MODE)
        $softBoost = 0;
        if (preg_match('/โอนแล้ว|โอนเสร็จ|แจ้งสลิป|ส่งสลิป|ยืนยันการโอน/u', $text)) {
            $softBoost = max($softBoost, 55);
        }
        if (preg_match('/\[(PHONE_NUM|BANK_ACCOUNT|LINE_ID)\]/', $text)) {
            $softBoost = max($softBoost, 70);
        }
        if (preg_match('/ชื่อบัญชี|ชื่อ\s*บัญชี/u', $text)) {
            $softBoost = max($softBoost, 55);
        }
        if (preg_match('/พร[อ็อ]*มเพ[ลย์]|พรอมเพล|พร้อมเพล/u', $text)) {
            $softBoost = max($softBoost, 65);
        }
        if ($softBoost > 0) {
            $score = max($score, $softBoost + random_int(-5, 8));
            $score = (int) max(0, min(100, $score));
        }

        // Slight chance to diverge from regex for realistic disagreement
        if ($softBoost === 0 && random_int(1, 100) <= 12) {
            $score = (int) max(0, min(100, $score + random_int(-25, 25)));
        }

        if ($score >= 70) {
            $verdict = 'reject';
            $reasons = ['ตรวจพบการชักชวนชำระหรือติดต่อนอกแพลตฟอร์ม (mock)'];
            $categories = ['off_platform_transfer'];
        } elseif ($score >= 45) {
            $verdict = 'flag';
            $reasons = ['ข้อความมีลักษณะกำกวม ควรติดตาม (mock)'];
            $categories = ['phone_contact'];
        } else {
            $verdict = 'pass';
            $reasons = [];
            $categories = ['none'];
        }

        return [
            'risk_score' => $score,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'categories' => $categories,
            'mock' => true,
            'message_preview' => mb_substr($text, 0, 80),
        ];
    }

    /**
     * Dispute Assistant LLM JSON (pre-normalize).
     *
     * @return array<string, mixed>
     */
    public static function disputeAiJson(float $orderAmount = 1000.0, ?string $forcedAction = null): array {
        self::simulateLatency(600, 2000);

        $action = $forcedAction ?: self::consumeDisputeAction();
        if ($action === null) {
            $actions = ['REFUND_BUYER', 'PAYOUT_SELLER', 'SPLIT', 'NEEDS_REVIEW'];
            $action = $actions[random_int(0, 3)];
        }
        $action = strtoupper($action);

        if ($action === 'REFUND_BUYER') {
            $buyerFault = random_int(20, 40);
            $sellerFault = 100 - $buyerFault;
            $buyerRefund = $orderAmount;
            $sellerPayout = 0.0;
            $confidence = random_int(70, 92);
            $summary = 'แนะนำคืนเงินผู้ซื้อ (mock) — ผู้ขายน่าจะผิดส่วนใหญ่';
        } elseif ($action === 'PAYOUT_SELLER') {
            $buyerFault = random_int(60, 85);
            $sellerFault = 100 - $buyerFault;
            $buyerRefund = 0.0;
            $sellerPayout = $orderAmount;
            $confidence = random_int(68, 90);
            $summary = 'แนะนำจ่ายเงินผู้ขาย (mock) — มีหลักฐานจัดส่งแล้ว';
        } elseif ($action === 'SPLIT') {
            $buyerFault = random_int(40, 60);
            $sellerFault = 100 - $buyerFault;
            $buyerRefund = round($orderAmount * ($buyerFault / 100), 2);
            $sellerPayout = round($orderAmount - $buyerRefund, 2);
            $confidence = random_int(50, 75);
            $summary = 'แนะนำแบ่งเงิน (mock) — ความผิดทั้งสองฝ่าย';
        } else {
            $action = 'NEEDS_REVIEW';
            $buyerFault = 50;
            $sellerFault = 50;
            $buyerRefund = 0.0;
            $sellerPayout = 0.0;
            $confidence = random_int(35, 55);
            $summary = 'หลักฐานไม่เพียงพอ ควรให้แอดมินตรวจเพิ่ม (mock)';
        }

        return [
            'summary' => $summary,
            'buyer_fault_pct' => $buyerFault,
            'seller_fault_pct' => $sellerFault,
            'recommended_action' => $action,
            'suggested_buyer_refund' => $buyerRefund,
            'suggested_seller_payout' => $sellerPayout,
            'confidence' => $confidence,
            'key_findings' => ['ผลวิเคราะห์จำลองสำหรับ load test', 'อ้างอิงสถานะพัสดุและเหตุผลข้อพิพาท'],
            'reasoning' => 'Mock Dispute AI — ใช้ยืนยัน Logic Flow ของ DisputeAssistantService',
            'mock' => true,
        ];
    }
}
