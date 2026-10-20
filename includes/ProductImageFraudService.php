<?php
// includes/ProductImageFraudService.php
// Tier 1: EXIF metadata (free) → Tier 2: Google Cloud Vision (only suspicious images)

require_once __DIR__ . '/MockApiHelper.php';

class ProductImageFraudService {
    public const PROVIDER_OFF = 'off';
    public const PROVIDER_EXIF = 'exif';
    public const PROVIDER_CLOUD_VISION = 'cloud_vision';

    public const VERDICT_PASS = 'pass';
    public const VERDICT_FLAG = 'flag';
    public const VERDICT_REJECT = 'reject';

    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public static function isCloudVisionConfigured(): bool {
        if (MockApiHelper::isEnabled()) {
            return true;
        }
        return defined('GOOGLE_CLOUD_VISION_API_KEY') && GOOGLE_CLOUD_VISION_API_KEY !== '';
    }

    public static function isEnabled(): bool {
        $configured = getenv('IMAGE_FRAUD_PROVIDER') ?: 'auto';
        if ($configured === self::PROVIDER_OFF) {
            return false;
        }
        return true;
    }

    /**
     * @param string[] $absolutePaths
     */
    public function scanImages(array $absolutePaths, array $context = []): array {
        if (!self::isEnabled()) {
            return $this->skippedResult();
        }

        $perImage = [];
        foreach ($absolutePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $perImage[] = $this->scanSingleImage($path, $context);
        }

        if (empty($perImage)) {
            return $this->skippedResult();
        }

        return $this->aggregateResults($perImage);
    }

    public function formatUserMessage(array $result): string {
        $reasons = $result['reasons'] ?? [];
        if (empty($reasons)) {
            return 'รูปภาพสินค้าไม่ผ่านการตรวจสอบความปลอดภัย กรุณาใช้รูปถ่ายจริงจากกล้องมือถือของสินค้าที่คุณขาย';
        }
        return 'รูปภาพสินค้าไม่ผ่านการตรวจสอบ: ' . implode('; ', array_slice($reasons, 0, 3));
    }

    public function logScans(?int $productId, array $perImageResults): void {
        if (!$this->hasScansTable()) {
            return;
        }

        $sql = "INSERT INTO product_image_fraud_scans
                (product_id, file_path, provider, verdict, risk_score, reasons, raw_response)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($perImageResults as $row) {
            $reasonsJson = !empty($row['reasons']) ? json_encode($row['reasons'], JSON_UNESCAPED_UNICODE) : null;
            $rawJson = !empty($row['raw']) ? json_encode($row['raw'], JSON_UNESCAPED_UNICODE) : null;
            $filePath = (string)($row['file_path'] ?? '');
            $provider = (string)($row['provider'] ?? self::PROVIDER_EXIF);
            $verdict = (string)($row['verdict'] ?? self::VERDICT_PASS);
            $riskScore = (float)($row['risk_score'] ?? 0);
            $stmt->bind_param(
                'isssdss',
                $productId,
                $filePath,
                $provider,
                $verdict,
                $riskScore,
                $reasonsJson,
                $rawJson
            );
            $stmt->execute();
        }
    }

    private function scanSingleImage(string $absolutePath, array $context): array {
        $basename = basename($absolutePath);

        // Deterministic GT path for load tests under MOCK_API_MODE
        if (MockApiHelper::isEnabled()) {
            $forced = MockApiHelper::consumeImageFraudScenario();
            if ($forced === 'clean') {
                MockApiHelper::simulateLatency(400, 900);
                return [
                    'file_path' => $basename,
                    'provider' => self::PROVIDER_EXIF,
                    'risk_score' => (float) random_int(5, 25),
                    'verdict' => self::VERDICT_PASS,
                    'reasons' => [],
                    'matches_listing' => true,
                    'raw' => ['tier' => 1, 'mock_scenario' => 'clean', 'vision_skipped' => true],
                ];
            }
            if ($forced === 'risky') {
                // Re-set so cloudVisionAnnotate consumes the same intent
                MockApiHelper::setImageFraudScenario('risky');
                MockApiHelper::simulateLatency(200, 500);
                $exifResult = [
                    'trust_score' => 20,
                    'suspicious' => true,
                    'reasons' => ['จำลองภาพน่าสงสัยสำหรับ load test'],
                    'signals' => [],
                    'file_size' => (int) @filesize($absolutePath),
                    'has_exif' => false,
                    'camera_make' => null,
                    'camera_model' => null,
                    'has_gps' => false,
                ];
                try {
                    $visionResult = $this->analyzeWithCloudVision($absolutePath, $context, $exifResult);
                    $riskScore = max(0, min(100, (float) ($visionResult['risk_score'] ?? 80)));
                    $verdict = $this->resolveVerdict($riskScore, self::VERDICT_REJECT);
                    return [
                        'file_path' => $basename,
                        'provider' => self::PROVIDER_EXIF . '+' . self::PROVIDER_CLOUD_VISION,
                        'risk_score' => $riskScore,
                        'verdict' => $verdict,
                        'reasons' => $this->normalizeReasons($visionResult['reasons'] ?? ['พบภาพเสี่ยง (mock)']),
                        'matches_listing' => (bool) ($visionResult['matches_listing'] ?? false),
                        'raw' => ['tier' => 2, 'mock_scenario' => 'risky', 'vision' => $visionResult],
                    ];
                } catch (Throwable $e) {
                    return [
                        'file_path' => $basename,
                        'provider' => self::PROVIDER_EXIF,
                        'risk_score' => 55.0,
                        'verdict' => self::VERDICT_FLAG,
                        'reasons' => ['mock vision error: ' . $e->getMessage()],
                        'matches_listing' => true,
                        'raw' => ['tier' => 1, 'vision_error' => $e->getMessage()],
                        'scan_failed' => true,
                    ];
                }
            }
        }

        $exifResult = $this->analyzeExif($absolutePath);

        if (!$exifResult['suspicious']) {
            return [
                'file_path' => $basename,
                'provider' => self::PROVIDER_EXIF,
                'risk_score' => max(0, 100 - (float)$exifResult['trust_score']),
                'verdict' => self::VERDICT_PASS,
                'reasons' => [],
                'matches_listing' => true,
                'raw' => ['tier' => 1, 'exif' => $exifResult, 'vision_skipped' => true],
            ];
        }

        if (!self::isCloudVisionConfigured()) {
            $riskScore = 55.0;
            return [
                'file_path' => $basename,
                'provider' => self::PROVIDER_EXIF,
                'risk_score' => $riskScore,
                'verdict' => $this->resolveVerdict($riskScore, self::VERDICT_FLAG),
                'reasons' => $exifResult['reasons'],
                'matches_listing' => true,
                'raw' => ['tier' => 1, 'exif' => $exifResult, 'vision_skipped' => 'not_configured'],
            ];
        }

        try {
            $visionResult = $this->analyzeWithCloudVision($absolutePath, $context, $exifResult);
            $riskScore = max(0, min(100, (float)($visionResult['risk_score'] ?? 0)));
            $verdict = $this->resolveVerdict($riskScore, (string)($visionResult['verdict'] ?? self::VERDICT_PASS));
            $reasons = $this->normalizeReasons($visionResult['reasons'] ?? []);
            if (empty($reasons)) {
                $reasons = $exifResult['reasons'];
            }

            return [
                'file_path' => $basename,
                'provider' => self::PROVIDER_EXIF . '+' . self::PROVIDER_CLOUD_VISION,
                'risk_score' => $riskScore,
                'verdict' => $verdict,
                'reasons' => $reasons,
                'matches_listing' => (bool)($visionResult['matches_listing'] ?? true),
                'raw' => [
                    'tier' => 2,
                    'exif' => $exifResult,
                    'vision' => $visionResult,
                ],
            ];
        } catch (Throwable $e) {
            error_log('ProductImageFraudService Vision: ' . $e->getMessage());
            $riskScore = 55.0;
            return [
                'file_path' => $basename,
                'provider' => self::PROVIDER_EXIF,
                'risk_score' => $riskScore,
                'verdict' => $this->resolveVerdict($riskScore, self::VERDICT_FLAG),
                'reasons' => array_merge($exifResult['reasons'], ['ไม่สามารถตรวจสอบด้วย AI ได้ชั่วคราว']),
                'matches_listing' => true,
                'raw' => ['tier' => 1, 'exif' => $exifResult, 'vision_error' => $e->getMessage()],
                'scan_failed' => true,
            ];
        }
    }

    /**
     * Tier 1 — ตรวจ EXIF / ขนาดไฟล์ (ไม่เสียค่า API)
     *
     * @return array{trust_score:int,suspicious:bool,reasons:string[],signals:string[],file_size:int,has_exif:bool,camera_make:?string,camera_model:?string,has_gps:bool}
     */
    private function analyzeExif(string $absolutePath): array {
        $fileSize = (int)filesize($absolutePath);
        $mime = $this->detectMime($absolutePath);
        $minBytes = defined('IMAGE_FRAUD_MIN_FILE_BYTES') ? (int)IMAGE_FRAUD_MIN_FILE_BYTES : 80000;
        $trustThreshold = defined('IMAGE_FRAUD_EXIF_TRUST_THRESHOLD') ? (int)IMAGE_FRAUD_EXIF_TRUST_THRESHOLD : 65;

        $trustScore = 0;
        $signals = [];
        $reasons = [];
        $hasExif = false;
        $cameraMake = null;
        $cameraModel = null;
        $hasGps = false;
        $hasDateTime = false;

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolutePath, 'ANY_TAG', true);
            if (is_array($exif) && $exif !== []) {
                $hasExif = true;
                $flat = $this->flattenExif($exif);

                $cameraMake = $this->exifString($flat, ['Make', 'IFD0.Make']);
                $cameraModel = $this->exifString($flat, ['Model', 'IFD0.Model']);
                $hasDateTime = $this->exifString($flat, ['DateTimeOriginal', 'EXIF.DateTimeOriginal', 'DateTime']) !== null;
                $hasGps = isset($flat['GPSLatitude'], $flat['GPSLongitude'])
                    || (isset($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLongitude']));

                if ($cameraMake !== null) {
                    $trustScore += 25;
                    $signals[] = 'camera_make';
                }
                if ($cameraModel !== null) {
                    $trustScore += 25;
                    $signals[] = 'camera_model';
                }
                if ($hasDateTime) {
                    $trustScore += 20;
                    $signals[] = 'datetime_original';
                }
                if ($hasGps) {
                    $trustScore += 20;
                    $signals[] = 'gps';
                }

                $software = strtolower((string)($this->exifString($flat, ['Software', 'IFD0.Software']) ?? ''));
                if ($software !== '' && preg_match('/line|facebook|messenger|telegram|whatsapp|instagram|snapseed|canva/i', $software)) {
                    $trustScore = max(0, $trustScore - 15);
                    $reasons[] = 'พบว่าภาพผ่านแอปแชท/ตกแต่ง (' . $software . ')';
                }
            }
        }

        if (!$hasExif) {
            if ($mime === 'image/jpeg') {
                $reasons[] = 'ไม่พบข้อมูล EXIF ของกล้อง (มักเป็นภาพก็อปจากแชทหรือเว็บ)';
            } else {
                $trustScore += 15;
                $reasons[] = 'ไฟล์ ' . strtoupper(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'รูป') . ' ไม่มีข้อมูลกล้อง — ตรวจเพิ่มเติม';
            }
        }

        $smallFile = $fileSize > 0 && $fileSize < $minBytes;
        $hasCameraMeta = $cameraMake !== null || $cameraModel !== null;

        if ($smallFile && !$hasCameraMeta) {
            $trustScore = max(0, $trustScore - 20);
            $reasons[] = 'ไฟล์เล็กเกินไป (' . $this->formatBytes($fileSize) . ') — อาจถูกบีบอัดจากแชท';
        }

        $trustScore = max(0, min(100, $trustScore));
        $suspicious = $trustScore < $trustThreshold;

        if ($suspicious && empty($reasons)) {
            $reasons[] = 'ข้อมูลยืนยันต้นทางภาพไม่เพียงพอ';
        }

        return [
            'trust_score' => $trustScore,
            'suspicious' => $suspicious,
            'reasons' => array_values(array_unique($reasons)),
            'signals' => $signals,
            'file_size' => $fileSize,
            'has_exif' => $hasExif,
            'camera_make' => $cameraMake,
            'camera_model' => $cameraModel,
            'has_gps' => $hasGps,
        ];
    }

    /**
     * Tier 2 — Google Cloud Vision (เฉพาะภาพที่ Tier 1 มองว่าน่าสงสัย)
     */
    private function analyzeWithCloudVision(string $absolutePath, array $context, array $exifResult): array {
        $mime = $this->detectMime($absolutePath);
        $base64 = base64_encode((string)file_get_contents($absolutePath));

        $payload = [
            'requests' => [[
                'image' => ['content' => $base64],
                'features' => [
                    ['type' => 'SAFE_SEARCH_DETECTION'],
                    ['type' => 'WEB_DETECTION', 'maxResults' => 8],
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 12],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 1],
                ],
            ]],
        ];

        if (MockApiHelper::isEnabled()) {
            $response = MockApiHelper::cloudVisionAnnotate($payload);
        } else {
            $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode(GOOGLE_CLOUD_VISION_API_KEY);
            $response = $this->httpJson('POST', $url, $payload);
        }
        $annotation = $response['responses'][0] ?? [];

        if (isset($annotation['error'])) {
            throw new RuntimeException($annotation['error']['message'] ?? 'Cloud Vision error');
        }

        return $this->scoreCloudVisionResponse($annotation, $context, $exifResult);
    }

    private function scoreCloudVisionResponse(array $annotation, array $context, array $exifResult): array {
        $riskScore = 35.0;
        $reasons = [];
        $matchesListing = true;

        $safe = $annotation['safeSearchAnnotation'] ?? [];
        foreach (['adult', 'violence', 'racy'] as $key) {
            $level = strtoupper((string)($safe[$key] ?? 'UNKNOWN'));
            if (in_array($level, ['LIKELY', 'VERY_LIKELY'], true)) {
                $riskScore = max($riskScore, 90);
                $reasons[] = 'ตรวจพบเนื้อหาไม่เหมาะสมในภาพ';
                break;
            }
        }

        $web = $annotation['webDetection'] ?? [];
        $fullMatches = count($web['fullMatchingImages'] ?? []);
        $partialMatches = count($web['partialMatchingImages'] ?? []);
        $pages = $web['pagesWithMatchingImages'] ?? [];

        if ($fullMatches > 0) {
            $riskScore = max($riskScore, 85);
            $reasons[] = 'พบภาพเดียวกันบนอินเทอร์เน็ต (อาจก็อปจากเว็บอื่น)';
        } elseif ($partialMatches > 0) {
            $riskScore = max($riskScore, 65);
            $reasons[] = 'พบภาพคล้ายกันบนอินเทอร์เน็ต';
        }

        foreach ($pages as $page) {
            $pageUrl = strtolower((string)($page['url'] ?? ''));
            if (preg_match('/shopee|lazada|kaidee|facebook|fb\.com|line\.me|google\.|aliexpress|taobao/i', $pageUrl)) {
                $riskScore = max($riskScore, 80);
                $reasons[] = 'ภาพอาจมาจากเว็บขายของอื่น';
                break;
            }
        }

        $suspiciousLabels = [
            'screenshot' => 70,
            'screen' => 55,
            'display device' => 50,
            'mobile phone' => 45,
            'website' => 50,
            'font' => 40,
            'poster' => 45,
        ];
        foreach ($annotation['labelAnnotations'] ?? [] as $label) {
            $desc = strtolower((string)($label['description'] ?? ''));
            $score = (float)($label['score'] ?? 0);
            foreach ($suspiciousLabels as $needle => $addRisk) {
                if ($score >= 0.6 && str_contains($desc, $needle)) {
                    $riskScore = max($riskScore, (float)$addRisk);
                    if ($needle === 'screenshot') {
                        $reasons[] = 'ภาพน่าจะเป็น Screenshot ไม่ใช่รูปถ่ายสินค้า';
                    }
                }
            }
        }

        $text = '';
        foreach ($annotation['textAnnotations'] ?? [] as $i => $textAnn) {
            if ($i === 0) {
                $text = (string)($textAnn['description'] ?? '');
                break;
            }
        }
        if ($text !== '') {
            if (preg_match('/\b0[689]\d[\s\-]?\d{3}[\s\-]?\d{4}\b/u', $text)
                || preg_match('/line\s*[@:]|add\s*line|แอดไลน์|ติดต่อ|โทร\./ui', $text)) {
                $riskScore = max($riskScore, 75);
                $reasons[] = 'พบข้อความชวนติดต่อนอกแพลตฟอร์มในภาพ';
            }
        }

        foreach ($exifResult['reasons'] ?? [] as $reason) {
            $riskScore = min(100, $riskScore + 5);
            $reasons[] = $reason;
        }

        $reasons = array_values(array_unique($reasons));
        $riskScore = max(0, min(100, $riskScore));

        $verdict = self::VERDICT_PASS;
        if ($riskScore >= 75) {
            $verdict = self::VERDICT_REJECT;
        } elseif ($riskScore >= 50) {
            $verdict = self::VERDICT_FLAG;
        }

        $productName = mb_strtolower(trim((string)($context['product_name'] ?? '')));
        if ($productName !== '') {
            $labelTexts = array_map(static function ($label) {
                return mb_strtolower((string)($label['description'] ?? ''));
            }, $annotation['labelAnnotations'] ?? []);
            $joined = implode(' ', $labelTexts);
            if ($joined !== '' && !str_contains($joined, mb_strtolower(mb_substr($productName, 0, 4)))) {
                $matchesListing = false;
            }
        }

        return [
            'risk_score' => $riskScore,
            'verdict' => $verdict,
            'reasons' => array_slice($reasons, 0, 5),
            'matches_listing' => $matchesListing,
            'web_full_matches' => $fullMatches,
            'web_partial_matches' => $partialMatches,
        ];
    }

    private function resolveVerdict(float $riskScore, string $aiVerdict): string {
        $blockThreshold = defined('IMAGE_FRAUD_BLOCK_THRESHOLD') ? (float)IMAGE_FRAUD_BLOCK_THRESHOLD : 75.0;
        $flagThreshold = defined('IMAGE_FRAUD_FLAG_THRESHOLD') ? (float)IMAGE_FRAUD_FLAG_THRESHOLD : 50.0;

        if ($riskScore >= $blockThreshold || $aiVerdict === self::VERDICT_REJECT) {
            return self::VERDICT_REJECT;
        }
        if ($riskScore >= $flagThreshold || $aiVerdict === self::VERDICT_FLAG) {
            return self::VERDICT_FLAG;
        }
        return self::VERDICT_PASS;
    }

    /**
     * @param array<int, array<string, mixed>> $perImage
     */
    private function aggregateResults(array $perImage): array {
        $maxScore = 0.0;
        $worstVerdict = self::VERDICT_PASS;
        $reasons = [];
        $matchesListing = true;
        $providers = [];

        foreach ($perImage as $row) {
            $maxScore = max($maxScore, (float)($row['risk_score'] ?? 0));
            $worstVerdict = $this->worseVerdict($worstVerdict, (string)($row['verdict'] ?? self::VERDICT_PASS));
            foreach ($row['reasons'] ?? [] as $reason) {
                $reasons[] = $reason;
            }
            if (empty($row['matches_listing'])) {
                $matchesListing = false;
            }
            if (!empty($row['provider'])) {
                $providers[] = (string)$row['provider'];
            }
        }

        $provider = $providers ? implode(',', array_unique($providers)) : self::PROVIDER_EXIF;

        return [
            'provider' => $provider,
            'risk_score' => $maxScore,
            'verdict' => $worstVerdict,
            'status' => $this->verdictToStatus($worstVerdict),
            'reasons' => array_slice(array_values(array_unique(array_filter($reasons))), 0, 5),
            'matches_listing' => $matchesListing,
            'per_image' => $perImage,
        ];
    }

    private function verdictToStatus(string $verdict): string {
        if ($verdict === self::VERDICT_REJECT) {
            return 'rejected';
        }
        if ($verdict === self::VERDICT_FLAG) {
            return 'flagged';
        }
        return 'passed';
    }

    private function worseVerdict(string $current, string $candidate): string {
        $rank = [self::VERDICT_PASS => 0, self::VERDICT_FLAG => 1, self::VERDICT_REJECT => 2];
        return ($rank[$candidate] ?? 0) > ($rank[$current] ?? 0) ? $candidate : $current;
    }

    private function skippedResult(): array {
        return [
            'provider' => self::PROVIDER_OFF,
            'risk_score' => 0,
            'verdict' => self::VERDICT_PASS,
            'status' => 'skipped',
            'reasons' => [],
            'matches_listing' => true,
            'per_image' => [],
        ];
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

    /**
     * @param array<string, mixed> $exif
     * @param string[] $keys
     */
    private function exifString(array $exif, array $keys): ?string {
        foreach ($keys as $key) {
            if (!isset($exif[$key])) {
                continue;
            }
            $value = trim((string)$exif[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $exif
     * @return array<string, mixed>
     */
    private function flattenExif(array $exif): array {
        $flat = $exif;
        foreach (['IFD0', 'EXIF', 'GPS'] as $section) {
            if (!isset($exif[$section]) || !is_array($exif[$section])) {
                continue;
            }
            foreach ($exif[$section] as $key => $value) {
                $flat[$key] = $value;
                $flat[$section . '.' . $key] = $value;
            }
        }
        return $flat;
    }

    private function formatBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' B';
    }

    private function detectMime(string $path): string {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: 'image/jpeg';
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        return in_array($mime, $allowed, true) ? $mime : 'image/jpeg';
    }

    private function httpJson(string $method, string $url, array $payload, array $extraHeaders = []): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
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
        $result = $this->conn->query("SHOW TABLES LIKE 'product_image_fraud_scans'");
        $exists = $result && $result->num_rows > 0;
        return $exists;
    }
}
