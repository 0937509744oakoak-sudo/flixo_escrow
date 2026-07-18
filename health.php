<?php
/**
 * Public production health check — no secrets, no auth.
 * Visit: /health.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ok = true;
$checks = [];

try {
    require_once __DIR__ . '/includes/config.php';
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'config_failed', 'message' => $e->getMessage()]);
    exit;
}

$checks['php'] = PHP_VERSION;
$extRequired = ['mysqli', 'mbstring', 'json', 'openssl', 'fileinfo'];
$extStatus = [];
foreach ($extRequired as $ext) {
    $loaded = extension_loaded($ext);
    $extStatus[$ext] = $loaded ? 'ok' : 'missing';
    if (!$loaded) {
        $ok = false;
    }
}
$checks['extensions'] = $extStatus;
$checks['app_env'] = defined('APP_ENV') ? APP_ENV : null;
$checks['base_url'] = defined('BASE_URL') ? BASE_URL : null;
$checks['force_https'] = defined('FORCE_HTTPS') ? FORCE_HTTPS : null;
$checks['encryption_key_set'] = defined('ENCRYPTION_KEY') && strlen(ENCRYPTION_KEY) >= 32;
$checks['slip_key_set'] = defined('SLIP_API_KEY') && SLIP_API_KEY !== '';
$checks['ekyc_provider'] = defined('EKYC_PROVIDER') ? EKYC_PROVIDER : null;
$checks['aigen_key_set'] = defined('AIGEN_API_KEY') && AIGEN_API_KEY !== '';
$checks['request_host'] = $_SERVER['HTTP_HOST'] ?? null;
$checks['forwarded_proto'] = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
$checks['https'] = $_SERVER['HTTPS'] ?? null;

$sessionPath = ini_get('session.save_path') ?: '(default)';
$checks['session_save_path'] = $sessionPath;
$checks['session_path_writable'] = $sessionPath !== '(default)' ? is_writable($sessionPath) : true;

$uploadRoot = __DIR__ . '/uploads';
$checks['uploads_writable'] = is_dir($uploadRoot) && is_writable($uploadRoot);

try {
    require_once __DIR__ . '/includes/db.php';
    $checks['db'] = $conn instanceof mysqli && @$conn->ping() ? 'ok' : 'fail';
} catch (Throwable $e) {
    $checks['db'] = 'fail';
    $checks['db_error'] = $e->getMessage();
    $ok = false;
}

if (defined('APP_ENV') && APP_ENV === 'production') {
    require_once __DIR__ . '/includes/ProductionGuard.php';
    $guard = ProductionGuard::validate();
    $checks['production_guard'] = $guard === [] ? 'ok' : $guard;
    if ($guard !== []) {
        $ok = false;
    }
}

// Soft probe: can we encrypt/decrypt without throwing?
try {
    require_once __DIR__ . '/includes/PiiEncryption.php';
    $sample = PiiEncryption::encrypt('healthcheck');
    $back = PiiEncryption::decrypt($sample);
    $checks['pii_roundtrip'] = ($back === 'healthcheck') ? 'ok' : 'mismatch';
} catch (Throwable $e) {
    $checks['pii_roundtrip'] = 'fail';
    $checks['pii_error'] = $e->getMessage();
    $ok = false;
}

echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
