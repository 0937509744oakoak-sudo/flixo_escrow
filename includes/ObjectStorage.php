<?php
// includes/ObjectStorage.php
//
// S3-compatible object storage client (Cloudflare R2 / AWS S3) with NO external
// SDK — signs requests with AWS Signature V4 over cURL, matching how this
// project vendors dependencies manually (see PHPMailer/).
//
// Configure via environment variables (see .env.example):
//   S3_ENDPOINT     e.g. https://<accountid>.r2.cloudflarestorage.com   (R2)
//                   or   https://s3.<region>.amazonaws.com               (S3)
//   S3_REGION       'auto' for R2, e.g. 'ap-southeast-1' for S3
//   S3_BUCKET       bucket name
//   S3_ACCESS_KEY   access key id
//   S3_SECRET_KEY   secret access key
//   S3_PUBLIC_BASE  public base URL for objects (R2 public/dev domain or CDN),
//                   e.g. https://cdn.yourdomain.com  or  https://pub-xxxx.r2.dev
//
// When S3_* is not configured, callers should fall back to local disk so local
// development keeps working unchanged.

class ObjectStorage
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $key;
    private string $secret;
    private string $publicBase;

    public function __construct()
    {
        $this->endpoint   = rtrim((string) (getenv('S3_ENDPOINT') ?: ''), '/');
        $this->region     = (string) (getenv('S3_REGION') ?: 'auto');
        $this->bucket     = (string) (getenv('S3_BUCKET') ?: '');
        $this->key        = (string) (getenv('S3_ACCESS_KEY') ?: '');
        $this->secret     = (string) (getenv('S3_SECRET_KEY') ?: '');
        $this->publicBase = rtrim((string) (getenv('S3_PUBLIC_BASE') ?: ''), '/');
    }

    /**
     * True only when every required setting is present. Callers use this to
     * decide between cloud upload and local-disk fallback.
     */
    public function isConfigured(): bool
    {
        return $this->endpoint !== ''
            && $this->bucket !== ''
            && $this->key !== ''
            && $this->secret !== '';
    }

    /**
     * Upload an object and return its public URL.
     *
     * @param string $localPath   filesystem path to read the object body from
     * @param string $objectKey   object key, e.g. "chat/ab12cd.jpg"
     * @param string $contentType MIME type, e.g. "image/jpeg"
     * @throws RuntimeException on any failure
     */
    public function put(string $localPath, string $objectKey, string $contentType): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Object storage is not configured');
        }
        $body = @file_get_contents($localPath);
        if ($body === false) {
            throw new RuntimeException('Cannot read file for upload: ' . $localPath);
        }

        $objectKey = ltrim($objectKey, '/');
        $encodedUri = $this->canonicalUri($objectKey);

        $r = $this->request('PUT', $encodedUri, $body, [
            'content-type' => $contentType,
        ]);
        if ($r['code'] < 200 || $r['code'] >= 300) {
            throw new RuntimeException(
                "Object storage PUT failed (HTTP {$r['code']}): {$r['error']} " . substr($r['body'], 0, 300)
            );
        }

        return $this->publicUrl($objectKey);
    }

    /**
     * Fetch an object's bytes. Returns the body string, or null if the object
     * is missing (404) or any error occurs. Used to stream private files
     * (payment slips) through an authorized PHP endpoint instead of exposing
     * a public URL.
     */
    public function get(string $objectKey): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        try {
            $encodedUri = $this->canonicalUri(ltrim($objectKey, '/'));
            $r = $this->request('GET', $encodedUri, '', []);
            if ($r['code'] >= 200 && $r['code'] < 300) {
                return $r['body'];
            }
            if ($r['code'] !== 404) {
                error_log("ObjectStorage::get HTTP {$r['code']} for {$objectKey}: {$r['error']}");
            }
            return null;
        } catch (Throwable $e) {
            error_log('ObjectStorage::get failed — ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete an object. Returns true on success (or if already gone).
     * Never throws — deletion failures should not break the caller.
     */
    public function delete(string $objectKey): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            $encodedUri = $this->canonicalUri(ltrim($objectKey, '/'));
            $r = $this->request('DELETE', $encodedUri, '', []);
            return $r['code'] >= 200 && $r['code'] < 300;
        } catch (Throwable $e) {
            error_log('ObjectStorage::delete failed — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Derive an object key from a stored value which may be a full URL, an
     * existing key ("slips/abc.jpg"), or a legacy bare filename ("abc.jpg").
     */
    public function keyFromStored(string $stored, string $subdir): string
    {
        $stored = trim($stored);
        $subdir = trim($subdir, '/');

        if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            $path = ltrim((string) parse_url($stored, PHP_URL_PATH), '/');
            // Path-style endpoints include the bucket as the first segment.
            if ($this->bucket !== '' && str_starts_with($path, $this->bucket . '/')) {
                $path = substr($path, strlen($this->bucket) + 1);
            }
            return $path;
        }
        if (str_contains($stored, '/')) {
            return ltrim($stored, '/'); // already a key
        }
        return ($subdir !== '' ? $subdir . '/' : '') . basename($stored);
    }

    /**
     * Public URL for a stored object key.
     */
    public function publicUrl(string $objectKey): string
    {
        $objectKey = ltrim($objectKey, '/');
        if ($this->publicBase !== '') {
            return $this->publicBase . '/' . $objectKey;
        }
        // No public domain configured: fall back to the (usually private)
        // endpoint URL. In production you should set S3_PUBLIC_BASE.
        return $this->endpoint . $this->canonicalUri($objectKey);
    }

    // ---------------------------------------------------------------------
    // Internal: AWS Signature V4 (path-style addressing)
    // ---------------------------------------------------------------------

    /**
     * Build the canonical request URI (path style: /<bucket>/<key>), with each
     * path segment rawurlencoded but '/' preserved — as required by SigV4.
     */
    private function canonicalUri(string $objectKey): string
    {
        $segments = array_merge([$this->bucket], explode('/', $objectKey));
        $encoded = array_map(static fn ($s) => rawurlencode($s), $segments);
        return '/' . implode('/', $encoded);
    }

    /**
     * Sign and send a request.
     *
     * @param array<string,string> $extraHeaders lowercase header name => value
     * @return array{code:int, body:string, error:string}
     */
    private function request(string $method, string $canonicalUri, string $body, array $extraHeaders): array
    {
        $host  = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $now   = gmdate('Ymd\THis\Z');
        $today = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $headers = array_merge([
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $now,
        ], $extraHeaders);

        // Canonical headers must be sorted by lowercase header name.
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
            $signedHeaderNames[] = $name;
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        // Empty query string for these object operations.
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',                 // canonical query string
            $canonicalHeaders,  // trailing \n already included
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$today}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate    = hash_hmac('sha256', $today, 'AWS4' . $this->secret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 '
            . "Credential={$this->key}/{$scope}, "
            . "SignedHeaders={$signedHeaders}, "
            . "Signature={$signature}";

        $curlHeaders = ['Authorization: ' . $authorization];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($this->endpoint . $canonicalUri);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method === 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'code'  => $code,
            'body'  => is_string($resp) ? $resp : '',
            'error' => $err,
        ];
    }
}
