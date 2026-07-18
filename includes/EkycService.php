<?php
// includes/EkycService.php — eKYC provider abstraction (Sumsub + AiGen + Manual)

class EkycService {
    public const PROVIDER_MANUAL = 'manual';
    public const PROVIDER_SUMSUB = 'sumsub';
    public const PROVIDER_AIGEN = 'aigen';

    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public static function activeProvider(): string {
        $configured = strtolower(trim((string) (getenv('EKYC_PROVIDER') ?: (defined('EKYC_PROVIDER') ? EKYC_PROVIDER : 'auto'))));

        if ($configured === 'manual') {
            return self::PROVIDER_MANUAL;
        }
        if ($configured === 'aigen') {
            return self::isAigenConfigured() ? self::PROVIDER_AIGEN : self::PROVIDER_MANUAL;
        }
        if ($configured === 'sumsub') {
            return self::isSumsubConfigured() ? self::PROVIDER_SUMSUB : self::PROVIDER_MANUAL;
        }
        // auto: prefer explicit trial provider AiGen, then Sumsub, else manual
        if (self::isAigenConfigured()) {
            return self::PROVIDER_AIGEN;
        }
        if (self::isSumsubConfigured()) {
            return self::PROVIDER_SUMSUB;
        }
        return self::PROVIDER_MANUAL;
    }

    public static function isSumsubConfigured(): bool {
        return (defined('SUMSUB_APP_TOKEN') && SUMSUB_APP_TOKEN !== '')
            && (defined('SUMSUB_SECRET_KEY') && SUMSUB_SECRET_KEY !== '');
    }

    public static function isAigenConfigured(): bool {
        return defined('AIGEN_API_KEY') && AIGEN_API_KEY !== '';
    }

    public function getSumsubSdkToken(int $userId, string $username): array {
        if (!self::isSumsubConfigured()) {
            throw new RuntimeException('Sumsub is not configured.');
        }
        $externalUserId = 'flixo_' . $userId;
        $level = defined('SUMSUB_LEVEL_NAME') ? SUMSUB_LEVEL_NAME : 'id-and-liveness';

        $this->ensureSumsubApplicant($userId, $username, $externalUserId, $level);

        $body = json_encode([
            'userId' => $externalUserId,
            'levelName' => $level,
            'ttlInSecs' => 1200,
        ]);

        $response = $this->sumsubRequest('POST', '/resources/accessTokens/sdk', $body);

        $this->logSession($userId, self::PROVIDER_SUMSUB, $externalUserId, 'sdk_started', null, null, $response);

        return [
            'token' => $response['token'] ?? '',
            'userId' => $externalUserId,
            'levelName' => $level,
        ];
    }

    private function ensureSumsubApplicant(int $userId, string $username, string $externalUserId, string $level): void {
        $stmt = $this->conn->prepare("SELECT ekyc_applicant_id FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['ekyc_applicant_id'])) {
            return;
        }

        $payload = json_encode([
            'externalUserId' => $externalUserId,
            'info' => ['country' => 'THA'],
            'note' => 'FLIXO user: ' . $username,
        ], JSON_UNESCAPED_UNICODE);

        $path = '/resources/applicants?levelName=' . rawurlencode($level);
        $response = $this->sumsubRequest('POST', $path, $payload);
        $applicantId = $response['id'] ?? '';

        $upd = $this->conn->prepare("UPDATE users SET ekyc_provider = 'sumsub', ekyc_applicant_id = ?, kyc_status = 'in_progress' WHERE id = ?");
        $upd->bind_param('si', $applicantId, $userId);
        $upd->execute();
    }

    public function applyReviewResult(int $userId, string $answer, ?string $rejectLabels = null, ?array $raw = null): void {
        $answer = strtoupper($answer);
        if ($answer === 'GREEN') {
            $stmt = $this->conn->prepare("UPDATE users SET is_verified = 1, kyc_status = 'approved', kyc_verified_at = NOW(), ekyc_provider = 'sumsub' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $this->logSession($userId, self::PROVIDER_SUMSUB, null, 'completed', 'completed', 'GREEN', $raw);
        } elseif ($answer === 'RED') {
            $stmt = $this->conn->prepare("UPDATE users SET is_verified = 0, kyc_status = 'rejected' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $this->logSession($userId, self::PROVIDER_SUMSUB, null, 'rejected', 'completed', 'RED', $raw);
        } else {
            $stmt = $this->conn->prepare("UPDATE users SET kyc_status = 'pending' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $this->logSession($userId, self::PROVIDER_SUMSUB, null, 'retry', 'pending', $answer, $raw);
        }
    }

    /**
     * Verify Thai ID card + selfie via AiGen OCR and Face Compare.
     * Call while image files are still readable (before PII encryption).
     *
     * @return array{status:string,verified:bool,real_name:?string,message:string,score:?float}
     */
    public function verifyWithAigen(int $userId, string $idCardPath, string $selfiePath): array {
        if (!self::isAigenConfigured()) {
            throw new RuntimeException('AiGen is not configured.');
        }
        if (!is_file($idCardPath) || !is_file($selfiePath)) {
            throw new RuntimeException('ไม่พบไฟล์เอกสารยืนยันตัวตน');
        }

        $idB64 = base64_encode((string) file_get_contents($idCardPath));
        $selfieB64 = base64_encode((string) file_get_contents($selfiePath));

        $ocr = $this->aigenRequest('POST', '/aiscript/idcard/v3', [
            'image' => $idB64,
            'option' => [
                'return_face' => true,
                'do_field_validation' => true,
            ],
        ]);

        $ocrData = $ocr['data'][0] ?? null;
        if (!is_array($ocrData) || ($ocr['status'] ?? '') !== 'success') {
            $this->applyAigenResult($userId, false, null, ['stage' => 'ocr_failed', 'ocr' => $this->sanitizeOcrForLog($ocr)]);
            return [
                'status' => 'rejected',
                'verified' => false,
                'real_name' => null,
                'message' => 'อ่านบัตรประชาชนไม่สำเร็จ กรุณาถ่ายรูปบัตรให้ชัดเจน',
                'score' => null,
            ];
        }

        $realName = $this->extractThaiNameFromOcr($ocrData);
        $idNumber = trim((string) ($ocrData['id_number']['value'] ?? ''));
        if ($realName === '' || $idNumber === '') {
            $this->applyAigenResult($userId, false, null, ['stage' => 'ocr_incomplete', 'ocr' => $this->sanitizeOcrForLog($ocr)]);
            return [
                'status' => 'rejected',
                'verified' => false,
                'real_name' => null,
                'message' => 'อ่านชื่อหรือเลขบัตรไม่ครบ กรุณาถ่ายใหม่',
                'score' => null,
            ];
        }

        $threshold = defined('AIGEN_FACE_THRESHOLD') ? (int) AIGEN_FACE_THRESHOLD : 80;
        $face = $this->aigenRequest('POST', '/aiface/face-compare/v3', [
            'image1' => $idB64,
            'image2' => $selfieB64,
            'threshold' => $threshold,
            'image1_is_document' => true,
            'verified_document' => true,
        ]);

        $errorMsg = (string) ($face['error_message'] ?? '');
        if ($errorMsg !== '') {
            $this->applyAigenResult($userId, false, $realName, [
                'stage' => 'face_error',
                'ocr' => $this->sanitizeOcrForLog($ocr),
                'face' => $face,
            ]);
            return [
                'status' => 'rejected',
                'verified' => false,
                'real_name' => $realName,
                'message' => $this->aigenFaceErrorMessage($errorMsg),
                'score' => null,
            ];
        }

        $matched = !empty($face['match']);
        $score = isset($face['score']) ? (float) $face['score'] : null;
        $payload = [
            'ocr' => $this->sanitizeOcrForLog($ocr),
            'face' => $face,
            'real_name' => $realName,
            'id_number_masked' => $this->maskIdNumber($idNumber),
        ];

        if ($matched) {
            $this->applyAigenResult($userId, true, $realName, $payload);
            return [
                'status' => 'approved',
                'verified' => true,
                'real_name' => $realName,
                'message' => 'ยืนยันตัวตนสำเร็จ',
                'score' => $score,
            ];
        }

        $this->applyAigenResult($userId, false, $realName, $payload);
        return [
            'status' => 'rejected',
            'verified' => false,
            'real_name' => $realName,
            'message' => 'ใบหน้าไม่ตรงกับบัตรประชาชน (คะแนน: ' . ($score !== null ? round($score, 1) : '-') . ')',
            'score' => $score,
        ];
    }

    public function applyAigenResult(int $userId, bool $approved, ?string $realName, ?array $raw = null): void {
        if ($approved) {
            if ($realName !== null && $realName !== '') {
                $stmt = $this->conn->prepare("UPDATE users SET is_verified = 1, kyc_status = 'approved', kyc_verified_at = NOW(), ekyc_provider = 'aigen', real_name = ? WHERE id = ?");
                $stmt->bind_param('si', $realName, $userId);
            } else {
                $stmt = $this->conn->prepare("UPDATE users SET is_verified = 1, kyc_status = 'approved', kyc_verified_at = NOW(), ekyc_provider = 'aigen' WHERE id = ?");
                $stmt->bind_param('i', $userId);
            }
            $stmt->execute();
            $this->logSession($userId, self::PROVIDER_AIGEN, null, 'completed', 'completed', 'GREEN', $raw);
        } else {
            $stmt = $this->conn->prepare("UPDATE users SET is_verified = 0, kyc_status = 'rejected', ekyc_provider = 'aigen' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $this->logSession($userId, self::PROVIDER_AIGEN, null, 'rejected', 'completed', 'RED', $raw);
        }
    }

    /**
     * Mark documents uploaded; used when AiGen is unavailable so admin can review.
     */
    public function markAigenPending(int $userId, ?array $raw = null): void {
        $stmt = $this->conn->prepare("UPDATE users SET kyc_status = 'pending', ekyc_provider = 'aigen', is_verified = 0 WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $this->logSession($userId, self::PROVIDER_AIGEN, null, 'pending_manual', 'pending', null, $raw);
    }

    private function extractThaiNameFromOcr(array $ocrData): string {
        $name = trim((string) ($ocrData['name_th']['value'] ?? ''));
        $surname = trim((string) ($ocrData['surname_th']['value'] ?? ''));
        if ($name !== '' && $surname !== '') {
            return $name . ' ' . $surname;
        }
        $full = trim((string) ($ocrData['title_name_surname_th']['value'] ?? ''));
        if ($full === '') {
            return '';
        }
        // Strip common Thai titles for bank-name matching
        $full = preg_replace('/^(นาย|นางสาว|นาง|เด็กชาย|เด็กหญิง)\s+/u', '', $full);
        return trim((string) $full);
    }

    private function maskIdNumber(string $id): string {
        $id = preg_replace('/\D+/', '', $id) ?? '';
        if (strlen($id) < 5) {
            return '*****';
        }
        return substr($id, 0, 1) . str_repeat('*', max(0, strlen($id) - 5)) . substr($id, -4);
    }

    private function sanitizeOcrForLog(array $ocr): array {
        $data = $ocr['data'][0] ?? null;
        if (!is_array($data)) {
            return ['status' => $ocr['status'] ?? null];
        }
        $fields = [];
        foreach (['id_number', 'title_name_surname_th', 'name_th', 'surname_th', 'dob_th'] as $key) {
            if (!isset($data[$key]['value'])) {
                continue;
            }
            $val = (string) $data[$key]['value'];
            if ($key === 'id_number') {
                $val = $this->maskIdNumber($val);
            }
            $fields[$key] = [
                'value' => $val,
                'confidence' => $data[$key]['confidence'] ?? null,
            ];
        }
        return ['status' => $ocr['status'] ?? null, 'fields' => $fields];
    }

    private function aigenFaceErrorMessage(string $error): string {
        $upper = strtoupper($error);
        if (str_contains($upper, 'FACE_NOT_DETECTED')) {
            return 'ตรวจไม่พบใบหน้าในรูป กรุณาถ่ายใหม่ให้เห็นใบหน้าชัดเจน';
        }
        if (str_contains($upper, 'ID_CARD_NOT_DETECTED')) {
            return 'ตรวจไม่พบบัตรประชาชนในรูป กรุณาถ่ายบัตรให้ครบทั้งใบ';
        }
        if (str_contains($upper, 'UNSUPPORTED_FORMAT')) {
            return 'รูปแบบไฟล์ไม่รองรับ กรุณาใช้ JPG หรือ PNG';
        }
        return 'ตรวจสอบใบหน้าไม่สำเร็จ: ' . $error;
    }

    /**
     * Pull latest review from Sumsub and update local user record.
     * Required on localhost where webhooks cannot reach the app.
     */
    public function syncReviewFromSumsub(int $userId): array {
        if (!self::isSumsubConfigured()) {
            return ['synced' => false, 'verified' => false, 'status' => 'not_configured'];
        }

        $stmt = $this->conn->prepare("SELECT is_verified, kyc_status, ekyc_applicant_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) {
            return ['synced' => false, 'verified' => false, 'status' => 'user_not_found'];
        }

        if (!empty($user['is_verified']) || ($user['kyc_status'] ?? '') === 'approved') {
            return ['synced' => true, 'verified' => true, 'status' => 'approved', 'answer' => 'GREEN'];
        }

        $externalUserId = 'flixo_' . $userId;
        $path = '/resources/applicants/-;externalUserId=' . rawurlencode($externalUserId) . '/one';
        $data = $this->sumsubRequest('GET', $path);

        if (!empty($data['id']) && empty($user['ekyc_applicant_id'])) {
            $applicantId = (string) $data['id'];
            $upd = $this->conn->prepare("UPDATE users SET ekyc_applicant_id = ?, ekyc_provider = 'sumsub' WHERE id = ?");
            $upd->bind_param('si', $applicantId, $userId);
            $upd->execute();
        }

        $review = $data['review'] ?? [];
        $reviewStatus = strtolower((string) ($review['reviewStatus'] ?? ''));
        $answer = strtoupper((string) ($review['reviewResult']['reviewAnswer'] ?? ''));

        if ($reviewStatus === 'completed' && $answer !== '') {
            $this->applyReviewResult($userId, $answer, null, $data);
            return [
                'synced' => true,
                'verified' => $answer === 'GREEN',
                'status' => $answer === 'GREEN' ? 'approved' : ($answer === 'RED' ? 'rejected' : 'pending'),
                'answer' => $answer,
            ];
        }

        if ($reviewStatus === 'pending' || $reviewStatus === 'queued' || $reviewStatus === 'onhold') {
            $stmt = $this->conn->prepare("UPDATE users SET kyc_status = 'pending', ekyc_provider = 'sumsub' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            return ['synced' => true, 'verified' => false, 'status' => 'pending', 'answer' => $answer ?: null];
        }

        return ['synced' => false, 'verified' => false, 'status' => 'no_review', 'answer' => null];
    }

    public function findUserIdByExternalRef(string $externalUserId): ?int {
        if (preg_match('/^flixo_(\d+)$/', $externalUserId, $m)) {
            return (int)$m[1];
        }
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE ekyc_applicant_id = ? LIMIT 1");
        $stmt->bind_param('s', $externalUserId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    private function logSession(int $userId, string $provider, ?string $externalRef, string $status, ?string $reviewStatus, ?string $answer, ?array $raw): void {
        $json = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null;
        $sql = "INSERT INTO ekyc_sessions (user_id, provider, external_ref, status, review_status, review_answer, raw_payload) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('issssss', $userId, $provider, $externalRef, $status, $reviewStatus, $answer, $json);
        $stmt->execute();
    }

    private function aigenRequest(string $method, string $path, array $payload = []): array {
        $base = defined('AIGEN_API_BASE') ? rtrim(AIGEN_API_BASE, '/') : 'https://api.aigen.online';
        $body = $payload !== [] ? json_encode($payload) : '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-AIGEN-KEY: ' . AIGEN_API_KEY,
            ],
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('AiGen: เชื่อมต่อไม่ได้ (' . $curlErr . ')');
        }

        $data = json_decode($resp ?: '{}', true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($code === 401) {
            throw new RuntimeException('AiGen: API key ไม่ถูกต้อง');
        }
        if ($code === 403) {
            throw new RuntimeException('AiGen: ไม่มีสิทธิ์เรียก API นี้');
        }
        if ($code === 429) {
            throw new RuntimeException('AiGen: เครดิตหมดแล้ว กรุณาเติมเครดิต');
        }
        if ($code >= 500) {
            throw new RuntimeException('AiGen: เซิร์ฟเวอร์มีปัญหาชั่วคราว');
        }

        // Face compare / OCR business failures often use 400/422 with a JSON body
        if ($code === 400 || $code === 413 || $code === 422) {
            return $data;
        }

        if ($code >= 400) {
            $msg = $data['message'] ?? $data['error_message'] ?? $data['error'] ?? ('HTTP ' . $code);
            if (is_array($msg)) {
                $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
            }
            throw new RuntimeException('AiGen: ' . $msg);
        }

        return $data;
    }

    private function sumsubRequest(string $method, string $path, string $body = ''): array {
        $base = defined('SUMSUB_API_BASE') ? SUMSUB_API_BASE : 'https://api.sumsub.com';
        $url = $path;
        $ts = (string)time();
        $sig = hash_hmac('sha256', $ts . strtoupper($method) . $url . $body, SUMSUB_SECRET_KEY);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $base . $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body !== '' ? $body : null,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-App-Token: ' . SUMSUB_APP_TOKEN,
                'X-App-Access-Ts: ' . $ts,
                'X-App-Access-Sig: ' . $sig,
            ],
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($resp ?: '{}', true);
        if ($code >= 400) {
            $msg = $data['description'] ?? $data['error'] ?? 'Sumsub API error';
            throw new RuntimeException('Sumsub: ' . $msg);
        }
        return is_array($data) ? $data : [];
    }
}
