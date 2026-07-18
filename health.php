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

if (($checks['db'] ?? '') === 'ok' && isset($conn) && $conn instanceof mysqli) {
    $requiredTables = [
        'users', 'orders', 'products', 'messages', 'reviews',
        'wallets', 'wallet_transactions', 'order_status_logs',
    ];
    $tableStatus = [];
    foreach ($requiredTables as $table) {
        $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
        $res = @$conn->query("SHOW TABLES LIKE '{$safe}'");
        $exists = $res && $res->num_rows > 0;
        $tableStatus[$table] = $exists ? 'ok' : 'missing';
        if (!$exists) {
            $ok = false;
        }
    }
    $checks['tables'] = $tableStatus;
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

// Optional: /health.php?diag=1&token=...  (token = first 32 of sha256(ENCRYPTION_KEY + ":diag"))
$diagToken = $_GET['token'] ?? '';
$wantDiag = isset($_GET['diag']) && $_GET['diag'] === '1';
if ($wantDiag && defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') {
    $expected = substr(hash('sha256', ENCRYPTION_KEY . ':diag'), 0, 32);
    if (hash_equals($expected, (string) $diagToken)) {
        require_once __DIR__ . '/includes/ErrorLogger.php';
        $checks['last_error'] = ErrorLogger::readLastError();
        $logFile = ErrorLogger::logFilePath();
        if ($logFile && is_file($logFile)) {
            $tail = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $checks['log_tail'] = $tail ? array_slice($tail, -15) : [];
        }
    } else {
        $checks['diag'] = 'invalid_token';
    }
}

echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
