<?php
// includes/EkycService.php — eKYC provider abstraction (Sumsub + Manual)

class EkycService {
    public const PROVIDER_MANUAL = 'manual';
    public const PROVIDER_SUMSUB = 'sumsub';

    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public static function activeProvider(): string {
        $configured = getenv('EKYC_PROVIDER') ?: 'auto';
        if ($configured === 'manual') {
            return self::PROVIDER_MANUAL;
        }
        if ($configured === 'sumsub' || ($configured === 'auto' && self::isSumsubConfigured())) {
            return self::PROVIDER_SUMSUB;
        }
        return self::PROVIDER_MANUAL;
    }

    public static function isSumsubConfigured(): bool {
        return (defined('SUMSUB_APP_TOKEN') && SUMSUB_APP_TOKEN !== '')
            && (defined('SUMSUB_SECRET_KEY') && SUMSUB_SECRET_KEY !== '');
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
