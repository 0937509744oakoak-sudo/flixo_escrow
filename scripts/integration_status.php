<?php
/**
 * Safe integration status — prints only booleans / mode labels, never secret values.
 *
 * Usage: php scripts/integration_status.php
 *        php scripts/integration_status.php --env=.env
 */
$envFile = __DIR__ . '/../.env';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envFile = substr($arg, 6);
    }
}

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/EkycService.php';

function modeFromOmise(?string $public, ?string $secret): string {
    $probe = (string) (($public !== '' && $public !== null) ? $public : $secret);
    if ($probe === '') {
        return 'unset';
    }
    if (str_contains($probe, '_test_')) {
        return 'test';
    }
    if (str_contains($probe, '_live_')) {
        return 'live';
    }
    return 'unknown';
}

function yn(bool $v): string {
    return $v ? 'YES' : 'no';
}

$rows = [
    ['MOCK_API_MODE', yn(defined('MOCK_API_MODE') && MOCK_API_MODE)],
    ['APP_ENV', defined('APP_ENV') ? APP_ENV : '?'],
    ['Thunder Slip API key', yn(defined('SLIP_API_KEY') && SLIP_API_KEY !== '')],
    ['Omise configured', yn(defined('OMISE_PUBLIC_KEY') && OMISE_PUBLIC_KEY !== '' && defined('OMISE_SECRET_KEY') && OMISE_SECRET_KEY !== '')],
    ['Omise mode', modeFromOmise(
        defined('OMISE_PUBLIC_KEY') ? OMISE_PUBLIC_KEY : '',
        defined('OMISE_SECRET_KEY') ? OMISE_SECRET_KEY : ''
    )],
    ['Google Sign-In', yn(defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '')],
    ['Sumsub API', yn(defined('SUMSUB_APP_TOKEN') && SUMSUB_APP_TOKEN !== '' && defined('SUMSUB_SECRET_KEY') && SUMSUB_SECRET_KEY !== '')],
    ['Sumsub webhook', yn(defined('SUMSUB_WEBHOOK_SECRET') && SUMSUB_WEBHOOK_SECRET !== '')],
    ['AiGen', yn(defined('AIGEN_API_KEY') && AIGEN_API_KEY !== '')],
    ['ThaiD', yn(defined('THAID_ENABLED') && THAID_ENABLED)],
    ['Cloud Vision', yn(defined('GOOGLE_CLOUD_VISION_API_KEY') && GOOGLE_CLOUD_VISION_API_KEY !== '')],
    ['Groq', yn(defined('GROQ_API_KEY') && GROQ_API_KEY !== '')],
    ['OpenRouter', yn(defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '')],
    ['Gemini', yn(defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '')],
    ['Mail enabled', yn(defined('MAIL_ENABLED') && MAIL_ENABLED)],
    ['EKYC_PROVIDER (config)', defined('EKYC_PROVIDER') ? EKYC_PROVIDER : '?'],
    ['eKYC active provider', EkycService::activeProvider()],
];

echo "FLIXO integration status (no secrets)\n";
echo str_repeat('-', 48) . "\n";
foreach ($rows as [$label, $value]) {
    echo str_pad($label, 28) . $value . "\n";
}
