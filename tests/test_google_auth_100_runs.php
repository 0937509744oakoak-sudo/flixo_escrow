<?php
/**
 * Google Sign-In token verification experiment — N=100
 * Uses real Google certs fetch + synthetic JWT negative cases.
 * Valid live GIS tokens require interactive login (not included).
 *
 * Cases: G_CERTS×20 | G_TAMPER×20 | G_WRONG_AUD×20 | G_EXPIRED×20 | G_MALFORMED×10 | G_NO_CONFIG×10
 *
 * Usage: C:\xampp\php\php.exe tests/test_google_auth_100_runs.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/GoogleIdTokenVerifier.php';

const TOTAL = 100;
$csvPath = __DIR__ . '/google_auth_results.csv';

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function fakeJwt(array $header, array $payload, string $sig = 'not-a-real-signature'): string
{
    return b64url(json_encode($header, JSON_UNESCAPED_SLASHES))
        . '.' . b64url(json_encode($payload, JSON_UNESCAPED_SLASHES))
        . '.' . b64url($sig);
}

function fetchCertsMeta(): array
{
    $t0 = microtime(true);
    $ch = curl_init('https://www.googleapis.com/oauth2/v1/certs');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $decoded = json_decode((string) $body, true);
    $ok = ($code === 200 && is_array($decoded) && $decoded !== []);
    $kid = $ok ? (string) array_key_first($decoded) : '';
    return ['ok' => $ok, 'http' => $code, 'ms' => $ms, 'kid' => $kid, 'count' => $ok ? count($decoded) : 0];
}

$clientId = defined('GOOGLE_CLIENT_ID') ? (string) GOOGLE_CLIENT_ID : '';
if ($clientId === '') {
    fwrite(STDERR, "WARNING: GOOGLE_CLIENT_ID empty — G_NO_CONFIG will dominate config tests.\n");
}

$certs = fetchCertsMeta();
if (!$certs['ok']) {
    fwrite(STDERR, "Cannot fetch Google certs — abort.\n");
    exit(1);
}

$plan = [];
foreach ([
    ['G_CERTS', 20],
    ['G_TAMPER', 20],
    ['G_WRONG_AUD', 20],
    ['G_EXPIRED', 20],
    ['G_MALFORMED', 10],
    ['G_NO_CONFIG', 10],
] as [$c, $n]) {
    for ($i = 0; $i < $n; $i++) {
        $plan[] = $c;
    }
}
shuffle($plan);

$fp = fopen($csvPath, 'w');
fputcsv($fp, ['No.', 'Case', 'Token Type', 'Verify Result', 'HTTP Certs Fetch', 'Latency ms', 'Pass?', 'Note']);

$pass = 0;
$falseAccept = 0;
$latencies = [];
$certsOk = 0;
$certsN = 0;

foreach ($plan as $i => $case) {
    $gtAccept = 0; // all cases expect reject / certs-only success — never false accept
    $tokenType = $case;
    $verifyResult = '';
    $httpCerts = '';
    $note = '';
    $ok = 0;
    $t0 = microtime(true);

    try {
        if ($case === 'G_CERTS') {
            $meta = fetchCertsMeta();
            $httpCerts = (string) $meta['http'];
            $certsN++;
            if ($meta['ok']) {
                $certsOk++;
            }
            $verifyResult = $meta['ok'] ? 'certs_ok' : 'certs_fail';
            $ok = $meta['ok'] ? 1 : 0;
            $note = 'kids=' . $meta['count'];
            // latency already in meta; still measure wrapper
        } elseif ($case === 'G_NO_CONFIG') {
            $v = new GoogleIdTokenVerifier('');
            try {
                $v->verify('a.b.c');
                $verifyResult = 'accepted';
                $ok = 0;
                $falseAccept++;
                $note = 'should have thrown';
            } catch (Throwable $e) {
                $verifyResult = 'rejected';
                $ok = 1;
                $note = $e->getMessage();
            }
            $httpCerts = 'n/a';
        } elseif ($case === 'G_MALFORMED') {
            $v = new GoogleIdTokenVerifier($clientId !== '' ? $clientId : 'dummy-client.apps.googleusercontent.com');
            try {
                $v->verify('not-a-jwt');
                $verifyResult = 'accepted';
                $ok = 0;
                $falseAccept++;
            } catch (Throwable $e) {
                $verifyResult = 'rejected';
                $ok = 1;
                $note = $e->getMessage();
            }
            $httpCerts = 'n/a';
        } else {
            $kid = $certs['kid'] !== '' ? $certs['kid'] : 'unknown';
            $header = ['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT'];
            $now = time();
            if ($case === 'G_EXPIRED') {
                $payload = [
                    'iss' => 'https://accounts.google.com',
                    'aud' => $clientId !== '' ? $clientId : 'dummy-client.apps.googleusercontent.com',
                    'sub' => '1234567890',
                    'email' => 'tester@example.com',
                    'email_verified' => true,
                    'iat' => $now - 7200,
                    'exp' => $now - 3600,
                ];
                $tokenType = 'expired';
            } elseif ($case === 'G_WRONG_AUD') {
                $payload = [
                    'iss' => 'https://accounts.google.com',
                    'aud' => 'wrong-client-id.apps.googleusercontent.com',
                    'sub' => '1234567890',
                    'email' => 'tester@example.com',
                    'email_verified' => true,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ];
                $tokenType = 'wrong_aud';
            } else { // G_TAMPER
                $payload = [
                    'iss' => 'https://accounts.google.com',
                    'aud' => $clientId !== '' ? $clientId : 'dummy-client.apps.googleusercontent.com',
                    'sub' => '1234567890',
                    'email' => 'tester@example.com',
                    'email_verified' => true,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ];
                $tokenType = 'tampered_sig';
            }
            $jwt = fakeJwt($header, $payload, 'tampered-' . bin2hex(random_bytes(32)));
            $v = new GoogleIdTokenVerifier($clientId !== '' ? $clientId : 'dummy-client.apps.googleusercontent.com');
            try {
                $v->verify($jwt);
                $verifyResult = 'accepted';
                $ok = 0;
                $falseAccept++;
                $note = 'FALSE ACCEPT';
            } catch (Throwable $e) {
                $verifyResult = 'rejected';
                $ok = 1;
                $note = $e->getMessage();
            }
            $httpCerts = 'cached/fetch';
        }
    } catch (Throwable $e) {
        $verifyResult = 'error';
        $ok = 0;
        $note = $e->getMessage();
    }

    $ms = (int) round((microtime(true) - $t0) * 1000);
    $latencies[] = $ms;
    if ($ok) {
        $pass++;
    }

    fputcsv($fp, [
        $i + 1,
        $case,
        $tokenType,
        $verifyResult,
        $httpCerts,
        $ms,
        $ok,
        $note,
    ]);
}
fclose($fp);

sort($latencies);
$n = count($latencies);
$avg = (int) round(array_sum($latencies) / max($n, 1));
$p95 = $latencies[(int) ceil(0.95 * $n) - 1];
$acc = round(100 * $pass / TOTAL, 2);
$certsRate = $certsN > 0 ? round(100 * $certsOk / $certsN, 2) : 0.0;

echo "Google Auth verify N=" . TOTAL . "\n";
echo "Pass={$pass}/" . TOTAL . " VerificationAccuracy={$acc}% FalseAccept={$falseAccept}\n";
echo "CertsFetchSuccess={$certsOk}/{$certsN} ({$certsRate}%)\n";
echo "Avg={$avg}ms P95={$p95}ms\n";
echo "CSV={$csvPath}\n";
echo "Note: no live valid GIS token in this run (negative+certs only)\n";
