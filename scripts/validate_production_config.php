#!/usr/bin/env php
<?php
/**
 * Validate production readiness for FLIXO Escrow (Phase 0).
 *
 * Usage:
 *   php scripts/validate_production_config.php
 *   php scripts/validate_production_config.php --strict
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ProductionGuard.php';
require_once __DIR__ . '/../includes/PiiEncryption.php';

$strict = in_array('--strict', $argv ?? [], true);
$warnings = [];
$passed = 0;
$failed = 0;

function check(bool $ok, string $label, string $failHint = ''): void {
    global $passed, $failed;
    if ($ok) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ {$label}" . ($failHint !== '' ? " — {$failHint}" : '') . "\n";
        $failed++;
    }
}

echo "========================================\n";
echo "FLIXO Production Config Validation\n";
echo "========================================\n\n";

echo "Environment: " . APP_ENV . "\n";
echo "BASE_URL: " . BASE_URL . "\n\n";

echo "1. CORE SECURITY\n";
check(APP_ENV !== 'production' || FORCE_HTTPS, 'FORCE_HTTPS', 'Set FORCE_HTTPS=1');
check(strlen(ENCRYPTION_KEY) >= 32, 'ENCRYPTION_KEY (>= 32 chars)', 'Generate: openssl rand -base64 32');
check(DB_PASS !== '', 'DB_PASS is set');
check(
    WEBHOOK_SECRET !== '' && WEBHOOK_SECRET !== 'FLIXO_SECRET_DEMO_2026',
    'WEBHOOK_SECRET (not demo default)'
);
check(!MOCK_API_MODE, 'MOCK_API_MODE off', 'eKYC and AI checks return simulated results while this is on');

echo "\n2. PAYMENT & INTEGRATIONS\n";
check(SLIP_API_KEY !== '', 'SLIP_API_KEY configured');
$omiseConfigured = OMISE_PUBLIC_KEY !== '' && OMISE_SECRET_KEY !== '';
check($omiseConfigured, 'Omise keys configured', 'Optional if using slip-only; required for card/PromptPay via Omise');
check(
    !str_starts_with(BASE_URL, 'http://localhost'),
    'BASE_URL is not localhost'
);

echo "\n3. ENCRYPTION SELF-TEST\n";
try {
    $sample = 'test-pii-' . random_int(1000, 9999);
    $enc = PiiEncryption::encrypt($sample);
    $dec = PiiEncryption::decrypt($enc);
    check($dec === $sample && PiiEncryption::isEncrypted($enc), 'PiiEncryption round-trip');
} catch (Throwable $e) {
    check(false, 'PiiEncryption round-trip', $e->getMessage());
}

echo "\n4. LOGGING\n";
check(defined('LOG_ERRORS') && LOG_ERRORS, 'LOG_ERRORS enabled', 'Set LOG_ERRORS=1 or APP_ENV=production');
$logDir = LOG_PATH !== '' ? LOG_PATH : ROOT_PATH . '/logs';
check(is_dir($logDir) || @mkdir($logDir, 0755, true), 'Log directory writable', $logDir);

if ($strict || APP_ENV === 'production') {
    echo "\n5. PRODUCTION GUARD (strict)\n";
    $guardErrors = ProductionGuard::validate($strict);
    if ($guardErrors === []) {
        check(true, 'ProductionGuard::validate() passed');
    } else {
        foreach ($guardErrors as $err) {
            check(false, $err);
        }
    }
}

echo "\n========================================\n";
echo "Result: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

$isLocal = defined('APP_ENV') && APP_ENV === 'local';
if ($failed > 0) {
    if ($isLocal && !$strict) {
        echo "\nLocal dev: some production checks skipped (use --strict to enforce all).\n";
        exit(0);
    }
    echo "\nFix issues in .env then re-run this script.\n";
    echo "Production checklist (.env.example) has all required keys.\n";
    exit(1);
}

echo "\nAll checks passed.\n";
exit(0);
