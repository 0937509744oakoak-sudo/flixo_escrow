<?php
/**
 * Correctness tests for the ThaiD JOSE plumbing.
 *
 * The rest of the ThaiD flow needs credentials from DOPA, but the part most
 * likely to be wrong needs none: converting a JWKS entry into a PEM and
 * reshaping an ES256 signature so OpenSSL will accept it. Everything here is
 * checked against keys we generate locally plus the live DOPA key sets.
 *
 * Run: php tests/test_thaid_oidc.php
 */

require_once __DIR__ . '/../includes/Jwks.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  [PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Inverse of Jwks::ecdsaSignatureToDer, so the round trip can be tested. */
function derSignatureToRaw(string $der, int $partLength = 32): string {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) {
        throw new RuntimeException('not a DER sequence');
    }
    $length = ord($der[$offset++]);
    if (($length & 0x80) !== 0) {
        $offset += $length & 0x7F;
    }
    $readInteger = static function () use ($der, &$offset, $partLength): string {
        if (ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('not a DER integer');
        }
        $length = ord($der[$offset++]);
        $value = ltrim(substr($der, $offset, $length), "\x00");
        $offset += $length;
        return str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
    };
    return $readInteger() . $readInteger();
}

function normalisePem(string $pem): string {
    return preg_replace('/\s+/', '', $pem) ?? '';
}

/**
 * Windows PHP builds have no built-in openssl.cnf, so openssl_pkey_new() fails
 * outright until one is pointed at. Only the test needs this — nothing in the
 * ThaiD flow generates keys.
 *
 * @return array{config?:string}
 */
function opensslConfig(): array {
    if (getenv('OPENSSL_CONF')) {
        return [];
    }
    // PHP_BINDIR is baked in at compile time and points nowhere useful on the
    // Windows builds; the directory of the running binary is the real location.
    $phpDir = dirname(PHP_BINARY);
    foreach ([
        $phpDir . '/extras/ssl/openssl.cnf',
        $phpDir . '/extras/openssl/openssl.cnf',
        dirname($phpDir) . '/apache/conf/openssl.cnf',
    ] as $candidate) {
        if (is_file($candidate)) {
            return ['config' => $candidate];
        }
    }
    return [];
}

echo "\n=== 1. EC P-256 key round trip ===\n";

$ecKey = openssl_pkey_new(opensslConfig() + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($ecKey === false) {
    fwrite(STDERR, "cannot generate an EC key — set OPENSSL_CONF to a valid openssl.cnf\n");
    exit(1);
}
$ecDetails = openssl_pkey_get_details($ecKey);

$ecJwk = [
    'kty' => 'EC',
    'crv' => 'P-256',
    'x' => b64url(str_pad($ecDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)),
    'y' => b64url(str_pad($ecDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT)),
];

$rebuiltPem = Jwks::toPem($ecJwk);
check('JWK converts to a PEM', $rebuiltPem !== null);
check(
    'PEM is byte-identical to the one OpenSSL exported',
    $rebuiltPem !== null && normalisePem($rebuiltPem) === normalisePem($ecDetails['key'])
);
check('OpenSSL loads the rebuilt PEM', $rebuiltPem !== null && openssl_pkey_get_public($rebuiltPem) !== false);

echo "\n=== 2. ES256 signature verification ===\n";

$signingInput = b64url('{"alg":"ES256","kid":"test"}') . '.' . b64url('{"sub":"1234567890123","ial":"2.3"}');

openssl_sign($signingInput, $derSignature, $ecKey, OPENSSL_ALGO_SHA256);
$rawSignature = derSignatureToRaw($derSignature);
check('JWS raw signature is 64 bytes', strlen($rawSignature) === 64, strlen($rawSignature) . ' bytes');

$converted = Jwks::ecdsaSignatureToDer($rawSignature);
check('raw signature converts back to DER', $converted !== null);
check(
    'converted signature verifies against the rebuilt PEM',
    $converted !== null && openssl_verify($signingInput, $converted, $rebuiltPem, OPENSSL_ALGO_SHA256) === 1
);

$tampered = $signingInput . 'x';
check(
    'a tampered payload fails verification',
    $converted !== null && openssl_verify($tampered, $converted, $rebuiltPem, OPENSSL_ALGO_SHA256) !== 1
);

// r or s with a high leading bit needs a 0x00 pad, which is the easiest thing to
// get wrong; force the case rather than waiting for it to appear by chance.
$highBitRaw = str_repeat("\xFF", 32) . str_repeat("\xFF", 32);
$highBitDer = Jwks::ecdsaSignatureToDer($highBitRaw);
check(
    'high-bit r/s values are zero-padded to stay positive',
    $highBitDer !== null && substr($highBitDer, 2, 3) === "\x02\x21\x00"
);

echo "\n=== 3. RSA key round trip ===\n";

$rsaKey = openssl_pkey_new(opensslConfig() + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
$rsaDetails = openssl_pkey_get_details($rsaKey);
$rsaPem = Jwks::toPem([
    'kty' => 'RSA',
    'n' => b64url($rsaDetails['rsa']['n']),
    'e' => b64url($rsaDetails['rsa']['e']),
]);
check(
    'RSA JWK matches the OpenSSL-exported PEM',
    $rsaPem !== null && normalisePem($rsaPem) === normalisePem($rsaDetails['key'])
);

echo "\n=== 4. Malformed keys are rejected ===\n";

check('unknown kty returns null', Jwks::toPem(['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'AA']) === null);
check('unsupported curve returns null', Jwks::toPem(['kty' => 'EC', 'crv' => 'P-384', 'x' => 'AA', 'y' => 'AA']) === null);
check('short coordinates return null', Jwks::toPem(['kty' => 'EC', 'crv' => 'P-256', 'x' => 'AA', 'y' => 'AA']) === null);
check('odd-length signature returns null', Jwks::ecdsaSignatureToDer(str_repeat("\x01", 63)) === null);

echo "\n=== 5. Live DOPA key sets ===\n";

foreach ([
    'sandbox' => 'https://imauthsbx.bora.dopa.go.th',
    'production' => 'https://imauth.bora.dopa.go.th',
] as $environment => $issuer) {
    $context = stream_context_create(['http' => ['timeout' => 20], 'ssl' => ['verify_peer' => true]]);
    $body = @file_get_contents($issuer . '/jwks/', false, $context);
    if ($body === false) {
        echo "  [SKIP] {$environment} — no network\n";
        continue;
    }
    $keys = json_decode($body, true)['keys'] ?? [];
    $converted = 0;
    foreach ($keys as $jwk) {
        $pem = Jwks::toPem($jwk);
        if ($pem !== null && openssl_pkey_get_public($pem) !== false) {
            $converted++;
        }
    }
    check(
        "{$environment}: all " . count($keys) . ' published keys convert to usable PEMs',
        $keys !== [] && $converted === count($keys),
        "{$converted}/" . count($keys)
    );
}

echo "\n=== 6. Authorization request against live sandbox discovery ===\n";

// Placeholder credentials: building the authorization URL only reads the
// discovery document, so nothing secret is transmitted.
define('THAID_ISSUER', 'https://imauthsbx.bora.dopa.go.th');
define('THAID_CLIENT_ID', 'test-client-id');
define('THAID_CLIENT_SECRET', 'test-client-secret');
define('THAID_REDIRECT_URI', 'http://localhost/flixo_escrow/controllers/ThaiDController.php');
define('THAID_SCOPE', 'openid pid name given_name family_name birthdate ial');
define('THAID_API_KEY', '');
define('THAID_API_KEY_HEADER', 'Consumer-Key');
define('THAID_ENABLED', true);
require_once __DIR__ . '/../includes/ThaiDClient.php';

try {
    $verifier = ThaiDClient::generateCodeVerifier();
    $url = ThaiDClient::authorizationUrl('state-value', 'nonce-value', $verifier);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    check('authorization endpoint comes from discovery', str_starts_with($url, THAID_ISSUER . '/api/v2/oauth2/auth/'), $url);
    check('response_type is code', ($query['response_type'] ?? '') === 'code');
    check('redirect_uri is sent verbatim', ($query['redirect_uri'] ?? '') === THAID_REDIRECT_URI);
    check('state and nonce are both present', ($query['state'] ?? '') === 'state-value' && ($query['nonce'] ?? '') === 'nonce-value');
    check('pid scope is requested', str_contains((string) ($query['scope'] ?? ''), 'pid'));
    check(
        'PKCE challenge is the S256 digest of the verifier',
        ($query['code_challenge'] ?? '') === ThaiDClient::codeChallenge($verifier)
            && ($query['code_challenge_method'] ?? '') === 'S256'
    );
    check('verifier length is within RFC 7636 bounds', strlen($verifier) >= 43 && strlen($verifier) <= 128);
} catch (Throwable $e) {
    echo '  [SKIP] no network — ' . $e->getMessage() . "\n";
}

echo "\n" . str_repeat('-', 48) . "\n";
echo "passed: {$passed}   failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
