<?php
/**
 * Generate a VAPID (Voluntary Application Server Identification) EC key pair
 * for Web Push. Run once, then paste the output into .env.
 *
 * Usage: php scripts/generate_vapid_keys.php
 */

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Pad/trim a big-endian integer to exactly $len bytes. */
function fixedLengthBytes(string $bin, int $len): string
{
    $bin = ltrim($bin, "\x00");
    if (strlen($bin) > $len) {
        $bin = substr($bin, -$len);
    }
    return str_pad($bin, $len, "\x00", STR_PAD_LEFT);
}

$config = [
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
];

$res = openssl_pkey_new($config);
if ($res === false) {
    fwrite(STDERR, "Failed to generate EC key pair: " . openssl_error_string() . "\n");
    exit(1);
}

$details = openssl_pkey_get_details($res);
if (!$details || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
    fwrite(STDERR, "Failed to read EC key details.\n");
    exit(1);
}

$x = fixedLengthBytes($details['ec']['x'], 32);
$y = fixedLengthBytes($details['ec']['y'], 32);
$d = fixedLengthBytes($details['ec']['d'], 32);

// Uncompressed EC point format (0x04 || X || Y) — what browsers expect as applicationServerKey.
$publicKey = base64UrlEncode("\x04" . $x . $y);
$privateKey = base64UrlEncode($d);

echo "VAPID key pair generated.\n\n";
echo "Add these to your .env:\n\n";
echo "VAPID_PUBLIC_KEY={$publicKey}\n";
echo "VAPID_PRIVATE_KEY={$privateKey}\n";
echo "VAPID_SUBJECT=mailto:support@yourdomain.com\n\n";
echo "VAPID_PUBLIC_KEY is also embedded client-side (safe to expose); VAPID_PRIVATE_KEY must stay secret.\n";
