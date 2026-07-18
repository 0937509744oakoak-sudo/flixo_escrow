<?php
require __DIR__ . '/../includes/config.php';

$key = AIGEN_API_KEY;
$ch = curl_init('https://api.aigen.online/aiscript/idcard/v3');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-AIGEN-KEY: ' . $key,
    ],
    CURLOPT_POSTFIELDS => json_encode(['image' => base64_encode('not-an-image')]),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo "http=$code\n";
if ($err) {
    echo "curl_error=$err\n";
}
echo "body=" . substr((string) $resp, 0, 300) . "\n";
echo "auth_ok=" . ($code !== 401 && $code !== 403 ? 'yes' : 'no') . "\n";
