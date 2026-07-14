<?php
// includes/OmiseService.php — Omise API client (Charges, Sources, PromptPay)

class OmiseService {
    private string $secretKey;

    public function __construct(?string $secretKey = null) {
        $this->secretKey = $secretKey ?? (defined('OMISE_SECRET_KEY') ? OMISE_SECRET_KEY : '');
        if ($this->secretKey === '') {
            throw new RuntimeException('OMISE_SECRET_KEY is not configured.');
        }
    }

    public static function isConfigured(): bool {
        return defined('OMISE_SECRET_KEY') && OMISE_SECRET_KEY !== ''
            && defined('OMISE_PUBLIC_KEY') && OMISE_PUBLIC_KEY !== '';
    }

    public static function toSatang(float $thb): int {
        return (int)round($thb * 100);
    }

    public function createCardCharge(string $token, int $amountSatang, string $description, array $metadata = []): array {
        $payload = [
            'amount' => $amountSatang,
            'currency' => 'thb',
            'card' => $token,
            'description' => mb_substr($description, 0, 255),
            'capture' => true,
            'metadata' => $metadata,
        ];
        return $this->request('POST', '/charges', $payload);
    }

    public function createPromptPaySource(int $amountSatang, string $description, array $metadata = []): array {
        $payload = [
            'amount' => $amountSatang,
            'currency' => 'thb',
            'type' => 'promptpay',
            'description' => mb_substr($description, 0, 255),
            'metadata' => $metadata,
        ];
        return $this->request('POST', '/sources', $payload);
    }

    public function retrieveCharge(string $chargeId): array {
        return $this->request('GET', '/charges/' . rawurlencode($chargeId));
    }

    public function retrieveSource(string $sourceId): array {
        return $this->request('GET', '/sources/' . rawurlencode($sourceId));
    }

    public function createChargeFromSource(string $sourceId, int $amountSatang, string $description, array $metadata = []): array {
        $payload = [
            'amount' => $amountSatang,
            'currency' => 'thb',
            'source' => $sourceId,
            'description' => mb_substr($description, 0, 255),
            'capture' => true,
            'metadata' => $metadata,
        ];
        return $this->request('POST', '/charges', $payload);
    }

    public function isPaid(array $charge): bool {
        return ($charge['status'] ?? '') === 'successful' && ($charge['paid'] ?? false) === true;
    }

    public function isSourceChargeable(array $source): bool {
        return ($source['charge_status'] ?? '') === 'chargeable';
    }

    private function request(string $method, string $path, array $data = []): array {
        $url = OMISE_API_BASE . $path;
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Omise connection failed: ' . $err);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Omise response.');
        }

        if ($httpCode >= 400) {
            $msg = $decoded['message'] ?? ($decoded['code'] ?? 'Omise API error');
            throw new RuntimeException('Omise: ' . $msg);
        }

        return $decoded;
    }
}
