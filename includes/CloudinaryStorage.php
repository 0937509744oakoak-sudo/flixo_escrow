<?php
/**
 * Minimal Cloudinary image upload client using the signed Upload API.
 * Credentials are read from CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and
 * CLOUDINARY_API_SECRET; no SDK or credentials are exposed to the browser.
 */
class CloudinaryStorage
{
    private string $cloudName;
    private string $apiKey;
    private string $apiSecret;

    public function __construct()
    {
        $this->cloudName = trim((string) (getenv('CLOUDINARY_CLOUD_NAME') ?: ''));
        $this->apiKey = trim((string) (getenv('CLOUDINARY_API_KEY') ?: ''));
        $this->apiSecret = trim((string) (getenv('CLOUDINARY_API_SECRET') ?: ''));
    }

    public function isConfigured(): bool
    {
        return $this->cloudName !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /** Upload an image and return Cloudinary's HTTPS delivery URL. */
    public function upload(string $localPath, string $folder, string $publicId, string $mime): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Cloudinary is not configured');
        }
        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            throw new RuntimeException('PHP cURL is required for Cloudinary uploads');
        }

        $timestamp = time();
        $params = [
            'folder' => trim($folder, '/'),
            'public_id' => $publicId,
            'timestamp' => $timestamp,
        ];
        ksort($params);
        $toSign = [];
        foreach ($params as $key => $value) {
            $toSign[] = $key . '=' . $value;
        }
        $signature = sha1(implode('&', $toSign) . $this->apiSecret);

        $post = $params + [
            'api_key' => $this->apiKey,
            'signature' => $signature,
            'file' => new CURLFile($localPath, $mime, basename($localPath)),
        ];
        $url = 'https://api.cloudinary.com/v1_1/' . rawurlencode($this->cloudName) . '/image/upload';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) {
            throw new RuntimeException('Cloudinary upload request failed: ' . $error);
        }
        $response = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($response) || empty($response['secure_url'])) {
            $message = is_array($response) ? (string) ($response['error']['message'] ?? '') : '';
            throw new RuntimeException('Cloudinary upload failed (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : ''));
        }

        return (string) $response['secure_url'];
    }
}
